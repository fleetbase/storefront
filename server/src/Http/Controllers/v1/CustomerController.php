<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Auth\AppleVerifier;
use Fleetbase\Auth\GoogleVerifier;
use Fleetbase\Exceptions\SmsBlockedException;
use Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException;
use Fleetbase\FleetOps\Http\Requests\UpdateContactRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Fleetbase\Models\UserDevice;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Storefront\Http\Requests\CreateCustomerRequest;
use Fleetbase\Storefront\Http\Requests\VerifyCreateCustomerRequest;
use Fleetbase\Storefront\Http\Resources\Customer;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Push\StorefrontPushChannel;
use Fleetbase\Storefront\Support\AppReview;
use Fleetbase\Storefront\Support\Storefront;
use Fleetbase\Storefront\Support\StorefrontSocket;
use Fleetbase\Support\SocketCluster\SocketToken;
use Fleetbase\Support\Utils;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    /**
     * Seconds inside which a repeated creation-code request answers "ok" without sending
     * again: the first code is still valid, and a second message would only cost money.
     */
    public const CREATION_CODE_RESEND_SECONDS = 60;

    /**
     * Wrong codes an identity may try inside the window before it is locked out, so a
     * six-digit code cannot be guessed from many addresses.
     */
    public const CODE_ATTEMPT_LIMIT  = 10;
    public const CODE_ATTEMPT_WINDOW = 900;

    /**
     * Wrong guesses a single code tolerates before it locks itself (core's check()).
     */
    public const CODE_CHECK_ATTEMPTS = 5;

    protected function authenticatedCustomerForRequest(Request $request): ?Contact
    {
        return Storefront::getCustomerFromToken();
    }

    /**
     * Query for Storefront Customer orders.
     *
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function registerDevice(Request $request)
    {
        $customer = Storefront::getCustomerFromToken();

        if (!$customer) {
            return response()->apiError('Not authorized to register device for cutomer');
        }

        $token    = trim((string) $request->input('token'));
        $platform = StorefrontPushChannel::normalizePlatform($request->or(['platform', 'os']));

        if ($token === '') {
            return response()->apiError('A device token is required.');
        }

        if (!$platform) {
            return response()->apiError('Device platform must be either ios or android.');
        }

        if (!$customer->user_uuid) {
            return response()->apiError('Unable to register device for customer without a user account.');
        }

        // A token identifies one app install, so it always belongs to the customer who
        // registered it most recently (e.g. after logging out and in as someone else).
        $devices = UserDevice::withTrashed()->where('token', $token)->orderByDesc('updated_at')->get();
        $device  = $devices->shift() ?? new UserDevice();
        $devices->each(fn (UserDevice $duplicate) => $duplicate->forceDelete());

        if ($device->exists && $device->trashed()) {
            $device->restore();
        }

        $device->fill([
            'user_uuid' => $customer->user_uuid,
            'platform'  => $platform,
            'token'     => $token,
            'status'    => 'active',
        ]);

        $optionalAttributes = [
            'environment'    => in_array($request->input('environment'), ['production', 'sandbox'], true) ? $request->input('environment') : null,
            'app_identifier' => session('storefront_store') ?? session('storefront_network'),
            'last_seen_at'   => now(),
        ];
        $columns = static::userDeviceColumns();
        foreach ($optionalAttributes as $column => $value) {
            if (in_array($column, $columns, true)) {
                $device->forceFill([$column => $value]);
            }
        }

        $device->save();

        return response()->json([
            'status' => 'OK',
            'device' => $device->public_id,
        ]);
    }

    /**
     * Unregister a device token for the authenticated customer, e.g. on logout.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function unregisterDevice(Request $request)
    {
        $customer = Storefront::getCustomerFromToken();

        if (!$customer) {
            return response()->apiError('Not authorized to unregister device for customer');
        }

        $token = trim((string) $request->input('token'));
        if ($token === '') {
            return response()->apiError('A device token is required.');
        }

        $deleted = UserDevice::where('token', $token)->where('user_uuid', $customer->user_uuid)->get()->each(fn (UserDevice $device) => $device->delete())->count();

        return response()->json([
            'status'  => 'OK',
            'deleted' => $deleted,
        ]);
    }

    /**
     * Mints a realtime socket token for the signed-in customer.
     *
     * POST storefront/v1/customers/socket-token — authenticated like every other
     * customer endpoint: the storefront key plus a Customer-Token header. The token
     * is a `customer` principal scoped to the store or network the key belongs to;
     * the socket server only lets it subscribe to channels the customer owns.
     * Returns 404 while socket authentication is not configured on this instance.
     */
    public function socketToken(Request $request)
    {
        if (!SocketToken::enabled()) {
            return response()->apiError('Not found.', 404);
        }

        $customer = Storefront::getCustomerFromToken();
        if (!$customer) {
            return response()->apiError('Not authorized to create a socket token for customer.', 401);
        }

        // A customer's token is only honoured by the storefront whose company the
        // customer belongs to, so a token minted against another company's key is refused.
        $storefront = Storefront::about();
        if (!$storefront || $storefront->company_uuid !== $customer->company_uuid) {
            return response()->apiError('Not authorized to create a socket token for customer.', 401);
        }

        return response()->json(SocketToken::issue(StorefrontSocket::customerPrincipal($customer, $storefront)));
    }

    /**
     * Newer core-api versions add push metadata columns to user_devices.
     *
     * @return string[]
     */
    protected static function userDeviceColumns(): array
    {
        return (new UserDevice())->getConnection()->getSchemaBuilder()->getColumnListing('user_devices');
    }

    /**
     * Query for Storefront Customer orders.
     *
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function orders(Request $request)
    {
        $customer = Storefront::getCustomerFromToken();

        if (!$customer) {
            return response()->apiError('Not authorized to view customers orders');
        }

        $results = Order::queryWithRequest($request, function (&$query) use ($customer) {
            $query->where('customer_uuid', $customer->uuid)->whereNull('deleted_at')->withoutGlobalScopes();

            // A multi-store checkout is one order to the customer: list its master order (which
            // shows each store's part) and leave out the stores' own orders.
            if (session('storefront_network')) {
                $query->whereNull('meta->master_order_id');
            }
        }, true);

        return OrderResource::collection($results);
    }

    /**
     * Query for Storefront Customer places.
     *
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function places(Request $request)
    {
        $customer = Storefront::getCustomerFromToken();

        if (!$customer) {
            return response()->apiError('Not authorized to view customers places');
        }

        $results = Place::queryWithRequest($request, function (&$query) use ($customer) {
            $query->where('owner_uuid', $customer->uuid);
        }, true);

        return PlaceResource::collection($results);
    }

    /**
     * Setups a verification request to create a new storefront customer.
     *
     * @return \Fleetbase\Http\Resources\Contact
     */
    public function requestCustomerCreationCode(VerifyCreateCustomerRequest $request)
    {
        $mode     = $request->input('mode', 'email');
        $identity = $request->input('identity');
        $isEmail  = Utils::isEmail($identity);
        $isPhone  = $mode === 'sms' && !$isEmail;
        $about    = Storefront::about(['company_uuid']);

        // validate identity
        if ($mode === 'email' && !$isEmail) {
            return response()->apiError('Invalid email provided for identity');
        }

        // prepare phone number
        if ($isPhone) {
            $identity = static::phone($identity);
        }

        // An app reviewer is sent nothing: they sign up with the store's App Review code. The
        // review account is shared by every store, so it may already exist; create() then
        // gives it a customer in this store.
        if ($reviewerResponse = static::reviewerSendResponse($identity, $about, ['status' => 'ok'])) {
            return $reviewerResponse;
        }

        // An identity that already has an account cannot ask for a creation code. Nothing
        // is checked or sent; the caller is told to sign in instead.
        if (static::accountExistsForIdentity($identity, $isEmail)) {
            return response()->apiError('An account already exists for this ' . ($isEmail ? 'email address' : 'phone number') . '. Sign in instead.', 422);
        }

        // A number that can never receive a text is refused before anything is written.
        if ($isPhone && ($rejection = static::phoneRejection($identity))) {
            return response()->apiError($rejection, 422);
        }

        // A code sent moments ago is still valid: answer as if it went out again rather
        // than paying for a second message.
        if (static::hasRecentCreationCode($identity)) {
            return response()->json(['status' => 'ok']);
        }

        // set contact attributes
        $attributes[$isEmail ? 'email' : 'phone'] = $identity;

        // create a customer instance
        $customer = new Contact($attributes);
        $meta     = ['identity' => $identity];

        try {
            if ($isEmail) {
                VerificationCode::generateEmailVerificationFor($customer, 'storefront_create_customer', [
                    'messageCallback' => function ($verification) use ($about) {
                        return "Your {$about->name} verification code is {$verification->code}";
                    },
                    'meta' => $meta,
                ]);
            } else {
                VerificationCode::generateSmsVerificationFor($customer, 'storefront_create_customer', [
                    'hashed'          => true,
                    'messageCallback' => function ($verification) use ($about) {
                        return "Your {$about->name} verification code is {$verification->code}";
                    },
                    'meta' => $meta,
                ]);
            }

            return response()->json(['status' => 'ok']);
        } catch (SmsBlockedException $e) {
            return static::blockedResponse($e);
        } catch (\Twilio\Exceptions\RestException $e) {
            return response()->apiError($e->getMessage());
        } catch (\Exception $e) {
            return response()->apiError(app()->hasDebugModeEnabled() ? $e->getMessage() : 'Error sending verification code.');
        }
    }

    /**
     * Creates a new Storefront Customer resource.
     *
     * @return \Fleetbase\Http\Resources\Contact
     */
    public function create(CreateCustomerRequest $request)
    {
        // get the verification token
        $code     = $request->input('code');
        $about    = Storefront::about(['company_uuid']);
        $input    = $request->only(['name', 'type', 'title', 'email', 'phone', 'meta']);
        $user     = null;

        // The code was filed against whatever identity requestCustomerCreationCode was
        // given. A client that just verified an address and now posts it as `email` should
        // not have to repeat it as `identity` — fall back to the payload before giving up.
        // Without this, a body of {name, email, code} left $identity null, static::phone()
        // turned it into the literal '+', and a perfectly good code was rejected.
        $identity = $request->input('identity') ?: $request->input('email') ?: $request->input('phone');

        if ($identity && !Utils::isEmail($identity)) {
            $identity = static::phone($identity);
        }

        if (blank($identity)) {
            return response()->apiError('An identity is required to create a customer.');
        }

        $attemptKey = 'create|' . static::attemptIdentity($identity, $about);
        if ($this->codeAttemptsExceeded($attemptKey)) {
            return response()->apiError('Too many attempts. Try again later.', 429);
        }

        // verify code: an app reviewer's App Review code, else the newest live code for this
        // identity, checked and used up
        if (!static::acceptsReviewerCode($identity, $code, $about) && !$this->verifyIdentityCode('storefront_create_customer', $identity, $code)) {
            $this->recordFailedCodeAttempt($attemptKey);

            return response()->apiError('Invalid verification code provided!');
        }

        $this->clearCodeAttempts($attemptKey);

        // A phone sign-up's texted code has just proved the number, so it is the account's
        // phone; without this the number only lived in the code and the app asked for it again.
        $verifiedPhone = Str::startsWith($identity, '+') ? $identity : null;
        $phone         = $request->filled('phone') ? static::phone($request->input('phone')) : $verifiedPhone;

        // check for existing user to attach contact to
        if (Utils::isEmail($identity)) {
            $user = User::where('email', $identity)->whereNull('deleted_at')->withoutGlobalScopes()->first();
        } elseif (Str::startsWith($identity, '+')) {
            $user = User::where('phone', $identity)->whereNull('deleted_at')->withoutGlobalScopes()->first();
        }

        if (!$user) {
            // create the user
            $user = User::create(array_merge(
                [
                    'company_uuid' => session('company'),
                    'phone'        => $phone,
                ],
                $request->only(['name', 'email', 'meta'])
            ));
            $user->setUserType('customer');
        } elseif (!$user->type) {
            $user->setUserType('customer');
        }

        if ($verifiedPhone && $user->phone === $verifiedPhone && !$user->phone_verified_at) {
            $user->update(['phone_verified_at' => now()]);
        }

        // always customer type
        $input['type']         = 'customer';
        $input['company_uuid'] = session('company');
        $input['phone']        = $phone;
        $input['user_uuid']    = $user->uuid;
        $input['meta']         = [
            'storefront_id' => $about->public_id,
            'origin'        => 'storefront',
        ];

        // Handle photo as either file id/ or base64 data string
        $photo = $request->input('photo');
        if ($photo) {
            // Handle photo being a file id
            if (Utils::isPublicId($photo)) {
                $file = File::where('public_id', $photo)->first();
                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }

            // Handle the photo being base64 data string
            if (Utils::isBase64String($photo)) {
                $path = implode('/', ['uploads', session('company'), 'customers']);
                $file = File::createFromBase64($photo, null, $path);
                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }
        }

        // create the customer/contact
        $customer = Contact::where(['company_uuid' => session('company'), 'user_uuid' => $user->uuid, 'type' => 'customer'])->first();
        if (!$customer) {
            try {
                $customer = Contact::create($input);
            } catch (UserAlreadyExistsException $e) {
                // If the exception is thrown because user already exists and
                // that user is the same user already assigned continue
                $customer = Contact::where(['company_uuid' => session('company'), 'user_uuid' => $user->uuid, 'type' => 'customer'])->first();
                if (!$customer) {
                    return response()->apiError($e->getMessage());
                }
            } catch (\Exception $e) {
                return response()->apiError($e->getMessage());
            }
        }

        // generate auth token
        try {
            $token = $user->createToken($customer->uuid);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        $customer->token = $token->plainTextToken;

        // response the customer resource
        return new Customer($customer);
    }

    /**
     * Updates a Storefront Customer resource.
     *
     * @param string                                        $id
     * @param \Fleetbase\Http\Requests\UpdateContactRequest $request
     *
     * @return \Fleetbase\Http\Resources\Contact
     */
    public function update($id, UpdateContactRequest $request)
    {
        $authenticatedCustomer = $this->authenticatedCustomerForRequest($request);
        if (!$authenticatedCustomer) {
            return response()->apiError('Not authorized to update customer.', 403);
        }

        if (Str::startsWith($id, 'customer')) {
            $id = Str::replaceFirst('customer', 'contact', $id);
        }

        // find for the contact
        try {
            $contact = Contact::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->apiError('Customer resource not found.');
        }

        if ($contact->uuid !== $authenticatedCustomer->uuid) {
            return response()->apiError('Not authorized to update customer.', 403);
        }

        // get request input
        $input = $request->only(['name', 'type', 'title', 'email', 'phone', 'meta']);

        // always customer type
        $input['type'] = 'customer';

        // If setting a default location for the contact
        if ($request->has('place')) {
            $input['place_uuid'] = Utils::getUuid('places', [
                'public_id'    => $request->input('place'),
                'company_uuid' => session('company'),
            ]);
        }

        // Handle photo as either file id/ or base64 data string
        $photo = $request->input('photo');
        if ($photo) {
            // Handle photo being a file id
            if (Utils::isPublicId($photo)) {
                $file = File::where('public_id', $photo)->first();
                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }

            // Handle the photo being base64 data string
            if (Utils::isBase64String($photo)) {
                $path = implode('/', ['uploads', session('company'), 'customers']);
                $file = File::createFromBase64($photo, null, $path);
                if ($file) {
                    $input['photo_uuid'] = $file->uuid;
                }
            }

            // Handle removal key
            if ($photo === 'REMOVE') {
                $input['photo_uuid'] = null;
            }
        }

        // update the contact
        try {
            $contact->update($input);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        // response the contact resource
        return new Customer($contact);
    }

    /**
     * Query for Storefront Customer resources.
     *
     * The storefront key is public (it ships inside every app build), so it can't be what
     * authorizes reading customers: this used to list every customer of the company to
     * anyone holding it. Only the customer signed in with the Customer-Token is returned.
     *
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function query(Request $request)
    {
        $customer = $this->authenticatedCustomerForRequest($request);
        if (!$customer) {
            return response()->apiError('Not authorized to view customers.', 401);
        }

        $results = Contact::queryWithRequestCached($request, function (&$query) use ($customer) {
            $query->where(['type' => 'customer', 'company_uuid' => session('company'), 'uuid' => $customer->uuid]);
        });

        return Customer::collection($results);
    }

    /**
     * Finds a single Storefront Customer resource: only the customer signed in with the
     * Customer-Token. A storefront key alone used to read any customer by id.
     *
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function find($id)
    {
        $customer = $this->authenticatedCustomerForRequest(request());
        if (!$customer) {
            return response()->apiError('Not authorized to view customer.', 401);
        }

        if (Str::startsWith($id, 'customer')) {
            $id = Str::replaceFirst('customer', 'contact', $id);
        }

        // find for the customer
        try {
            $contact = Contact::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->apiError('Customer resource not found.');
        }

        if ($contact->uuid !== $customer->uuid) {
            return response()->apiError('Not authorized to view customer.', 403);
        }

        // response the customer resource
        return new Customer($contact);
    }

    /**
     * Deletes a Storefront Product resources.
     *
     * @return \Fleetbase\Http\Resources\v1\DeletedResource
     */
    public function delete($id)
    {
        if (Str::startsWith($id, 'customer')) {
            $id = Str::replaceFirst('customer', 'contact', $id);
        }

        // find for the customer
        try {
            $contact = Contact::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->apiError('Customer resource not found.');
        }

        // delete the product
        $contact->delete();

        // response the customer resource
        return new DeletedResource($contact);
    }

    /**
     * Authenticates customer using login credentials and returns with auth token.
     *
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function login(Request $request)
    {
        $identity = $request->input('identity');
        $password = $request->input('password');
        $attrs    = $request->input(['name', 'phone', 'email']);

        // Guard the phone branch: with no identity to format, static::phone() returns null,
        // and `where('phone', null)` compiles to `phone IS NULL` — which would match an
        // arbitrary phone-less user rather than nobody.
        $identityPhone = static::phone($identity);
        $user          = User::where('email', $identity)
            ->when($identityPhone, fn ($query) => $query->orWhere('phone', $identityPhone))
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->apiError('Authentication failed using password provided.', 401);
        }

        // get the storefront or network logging in for
        $about = Storefront::about(['company_uuid']);

        // get contact record
        $contact = Contact::firstOrCreate(
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $about->company_uuid,
                'type'         => 'customer',
            ],
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $about->company_uuid,
                'name'         => $attrs['name'] ?? $user->name,
                'phone'        => $attrs['phone'] ?? $user->phone,
                'email'        => $attrs['email'] ?? $user->email,
                'type'         => 'customer',
            ]
        );

        // generate auth token
        try {
            $token = $user->createToken($contact->uuid);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        $contact->token = $token->plainTextToken;

        return new Customer($contact);
    }

    /**
     * Attempts authentication with phone number via SMS verification.
     *
     * @return \Illuminate\Http\Response
     */
    public function loginWithPhone()
    {
        $phone = static::phone();

        // Without a phone in the request there is nothing to look up. Falling through would
        // compile to `phone IS NULL` and hand back an arbitrary phone-less user.
        if (!$phone) {
            return response()->apiError('No customer with this phone # found.');
        }

        // get the storefront or network logging in for
        $about = Storefront::about();

        // An app reviewer is sent nothing: they sign in with the store's App Review code, and
        // verifyCode() creates the review account on its first sign-in.
        if ($reviewerResponse = static::reviewerSendResponse($phone, $about, ['status' => 'OK', 'method' => 'sms'])) {
            return $reviewerResponse;
        }

        // check if user exists
        $user = User::where('phone', $phone)->whereNull('deleted_at')->withoutGlobalScopes()->first();

        if (!$user) {
            return response()->apiError('No customer with this phone # found.');
        }

        // Generate the verification token.
        //
        // The SMS attempt is guarded because the Twilio SDK THROWS when the store has no
        // credentials configured — ConfigurationException, "Credentials are required to
        // create a Client" — and that propagated as a 500 carrying an HTML stack trace.
        // A store that has simply not set up SMS is not a server error.
        //
        // Falling back to email mirrors FleetOps' DriverController::loginWithPhone, and
        // means a store without Twilio can still authenticate its customers. `method`
        // tells the client which channel actually carried the code.
        $messageCallback = function ($verification) use ($about) {
            return "Your {$about->name} verification code is {$verification->code}";
        };

        $smsFailure = null;

        try {
            VerificationCode::generateSmsVerificationFor($user, 'storefront_login', [
                'hashed'          => true,
                'messageCallback' => $messageCallback,
            ]);

            return response()->json(['status' => 'OK', 'method' => 'sms']);
        } catch (\Throwable $e) {
            $smsFailure = $e;

            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }

            if ($user->email) {
                try {
                    VerificationCode::generateEmailVerificationFor($user, 'storefront_login', [
                        'messageCallback' => $messageCallback,
                    ]);

                    return response()->json(['status' => 'OK', 'method' => 'email']);
                } catch (\Throwable $e) {
                    if (app()->bound('sentry')) {
                        app('sentry')->captureException($e);
                    }
                }
            }
        }

        // With no email to fall back to, a refusal by the SMS guard is answered with its
        // own status so the app can tell "wait a minute" from "something broke".
        return static::sendFailureResponse($smsFailure, 'Unable to send verification code.');
    }

    /**
     * Handles user authentication via Apple Sign-In.
     *
     * This method validates the Apple ID token, checks if the user exists in the system,
     * and creates a new user if necessary. It then ensures a contact record exists for
     * the user and generates an authentication token.
     *
     * @param Request $request
     *                         The HTTP request containing the following required fields:
     *                         - `identityToken` (string): The token generated by Apple to identify the user.
     *                         - `authorizationCode` (string): The one-time code issued by Apple during login.
     *                         - `email` (string|null): The user's email address (provided on first login).
     *                         - `phone` (string|null): The user's phone number (optional).
     *                         - `name` (string|null): The user's full name (optional).
     *                         - `appleUserId` (string): A unique identifier for the user assigned by Apple.
     *
     * @return \Illuminate\Http\JsonResponse
     *                                       A JSON response containing the authenticated customer's details, including an access token
     *
     * @throws \Exception
     *                    If Apple authentication fails or any other error occurs during the process
     */
    public function loginWithApple(Request $request)
    {
        $identityToken     = $request->input('identityToken');
        $authorizationCode = $request->input('authorizationCode');
        $email             = $request->input('email');
        $phone             = $request->input('phone');
        $name              = $request->input('name');
        $appleUserId       = $request->input('appleUserId');

        if (!$identityToken || !$authorizationCode) {
            return response()->apiError('Missing required Apple authentication parameters.', 400);
        }

        try {
            // Verify the Apple token using the utility function.
            //
            // A malformed identityToken is client input, not a server fault, but the JWT
            // parser throws rather than returning false — so a bad token fell through to
            // the catch at the end of this method and came back as
            //   500 {"error":"The JWT string must have two dots"}
            // leaking the parser's own message. Any client sending a truncated or expired
            // token hit this. It is the same rejection as a token that parses but does not
            // verify, so it gets the same 400.
            try {
                $isValid = $this->verifyAppleIdentity($identityToken);
            } catch (\Throwable $verificationException) {
                Log::warning('[Storefront] Apple identity token could not be parsed.', [
                    'exception' => $verificationException->getMessage(),
                ]);

                $isValid = false;
            }

            if (!$isValid) {
                return response()->apiError('Apple ID authentication is not valid.', 400);
            }

            // Check if the user exists in the system
            $user = User::where(function ($query) use ($email, $appleUserId) {
                if ($email) {
                    $query->where('email', $email);
                    $query->orWhere('apple_user_id', $appleUserId);
                } else {
                    $query->where('apple_user_id', $appleUserId);
                }
            })->first();

            if (!$user) {
                // Create a new user
                $user = User::create([
                    'email'         => $email,
                    'phone'         => $phone,
                    'name'          => $name,
                    'apple_user_id' => $appleUserId,
                    'type'          => 'customer',
                    'company_uuid'  => session('company'),
                ]);
            } else {
                // Update the `apple_user_id` if it's not already set
                if (!$user->apple_user_id) {
                    $user->apple_user_id = $appleUserId;
                    $user->save();
                }
            }

            // Ensure a customer contact exists
            $contact = Contact::firstOrCreate(
                ['user_uuid' => $user->uuid, 'company_uuid' => session('company')],
                ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'meta' => ['apple_user_id' => $appleUserId], 'type' => 'customer']
            );

            // Generate an auth token
            $token          = $user->createToken($contact->uuid);
            $contact->token = $token->plainTextToken;

            return new Customer($contact);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage(), 500);
        }
    }

    /**
     * Handles user authentication via Facebook Sign-In.
     *
     * This method checks if the user exists in the system based on their email or Facebook ID.
     * If the user does not exist, it creates a new user and ensures a contact record is created.
     * Finally, it generates an authentication token for the user.
     *
     * @param Request $request
     *                         The HTTP request containing the following required fields:
     *                         - `email` (string|null): The user's email address.
     *                         - `name` (string|null): The user's full name.
     *                         - `facebookUserId` (string): A unique identifier for the user assigned by Facebook.
     *
     * @return \Illuminate\Http\JsonResponse
     *                                       A JSON response containing the authenticated customer's details, including an access token
     *
     * @throws \Exception
     *                    If Facebook authentication fails or any other error occurs during the process
     */
    public function loginWithFacebook(Request $request)
    {
        $email                = $request->input('email');
        $name                 = $request->input('name');
        $facebookUserId       = $request->input('facebookUserId');

        try {
            // Check if the user exists in the system
            $user = User::where(function ($query) use ($email, $facebookUserId) {
                if ($email) {
                    $query->where('email', $email);
                    $query->orWhere('facebook_user_id', $facebookUserId);
                } else {
                    $query->where('facebook_user_id', $facebookUserId);
                }
            })->first();

            if (!$user) {
                // Create a new user
                $user = User::create([
                    'email'            => $email,
                    'name'             => $name,
                    'facebook_user_id' => $facebookUserId,
                    'type'             => 'customer',
                    'company_uuid'     => session('company'),
                ]);
            } else {
                // Update the `facebook_user_id` if it's not already set
                if (!$user->facebook_user_id) {
                    $user->facebook_user_id = $facebookUserId;
                    $user->save();
                }
            }

            // Ensure a customer contact exists
            $contact = Contact::firstOrCreate(
                ['user_uuid' => $user->uuid, 'company_uuid' => session('company')],
                ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'meta' => ['facebook_user_id' => $facebookUserId], 'type' => 'customer']
            );

            // Generate an auth token
            $token          = $user->createToken($contact->uuid);
            $contact->token = $token->plainTextToken;

            return new Customer($contact);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage(), 500);
        }
    }

    /**
     * Handles user authentication via Google Sign-In.
     *
     * This method validates the Google ID token, retrieves user details from the token payload,
     * checks if the user exists in the system, and creates a new user if necessary.
     * It ensures a contact record exists for the user and generates an authentication token.
     *
     * @param Request $request
     *                         The HTTP request containing the following required fields:
     *                         - `idToken` (string): The token generated by Google to identify the user.
     *                         - `clientId` (string): The client ID associated with the app.
     *
     * @return \Illuminate\Http\JsonResponse
     *                                       A JSON response containing the authenticated customer's details, including an access token
     *
     * @throws \Exception
     *                    If Google authentication fails or any other error occurs during the process
     */
    public function loginWithGoogle(Request $request)
    {
        $idToken  = $request->input('idToken');
        $clientId = $request->input('clientId');
        if (!$idToken || !$clientId) {
            return response()->apiError('Missing required Google authentication parameters.', 400);
        }

        try {
            // Verify the Google ID token using the utility function
            $payload = $this->verifyGoogleIdentity($idToken, $clientId);
            if (!$payload) {
                return response()->apiError('Google Sign-In authentication is not valid.', 400);
            }

            // Extract user details from the payload
            $email        = data_get($payload, 'email');
            $name         = data_get($payload, 'name');
            $googleUserId = data_get($payload, 'sub');
            $avatarUrl    = data_get($payload, 'picture');

            // Check if the user exists in the system
            $user = User::where(function ($query) use ($email, $googleUserId) {
                if ($email) {
                    $query->where('email', $email);
                    $query->orWhere('google_user_id', $googleUserId);
                } else {
                    $query->where('google_user_id', $googleUserId);
                }
            })->first();

            if (!$user) {
                // Create a new user
                $user = User::create([
                    'email'          => $email,
                    'name'           => $name,
                    'google_user_id' => $googleUserId,
                    'type'           => 'customer',
                    'company_uuid'   => session('company'),
                ]);
            } else {
                // Update the `google_user_id` if it's not already set
                if (!$user->google_user_id) {
                    $user->google_user_id = $googleUserId;
                    $user->save();
                }
            }

            // Ensure a customer contact exists
            $contact = Contact::firstOrCreate(
                ['user_uuid' => $user->uuid, 'company_uuid' => session('company')],
                ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'meta' => ['google_user_id' => $googleUserId], 'type' => 'customer']
            );

            // Generate an auth token
            $token          = $user->createToken($contact->uuid);
            $contact->token = $token->plainTextToken;

            return new Customer($contact);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage(), 500);
        }
    }

    /**
     * Verifys SMS code and sends auth token with customer resource.
     *
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    /**
     * The create-customer request for a verify-code call made with
     * `for=storefront_create_customer`. Laravel resolves verifyCode()'s parameter as a plain
     * Request, and create() requires a CreateCustomerRequest, so passing it on as-is threw a
     * TypeError (500). This builds the form request from it and authorizes and validates it
     * the way the router does for `POST customers`: a bad code or duplicate email is a 422.
     */
    protected function createCustomerRequestFrom(Request $request): CreateCustomerRequest
    {
        if ($request instanceof CreateCustomerRequest) {
            return $request;
        }

        $createRequest = CreateCustomerRequest::createFrom($request);
        $createRequest->setContainer(app());
        if (app()->bound('redirect')) {
            $createRequest->setRedirector(app('redirect'));
        }
        $createRequest->validateResolved();

        return $createRequest;
    }

    /**
     * Whether a verification code should be accepted as an app-review bypass.
     *
     * App store reviewers cannot receive our SMS or email, so a fixed code has to keep
     * working in production. It used to be compared against the submitted code alone,
     * which meant anyone who learned it could authenticate as ANY customer. It is now
     * only honoured for an identity explicitly listed in storefront.review_accounts,
     * and both the code and the list must be configured.
     *
     * This is the instance-wide (env) fallback for self-hosted installs; a store's own
     * App Review access is checked by AppReview::acceptsCode().
     *
     * @param string|null $identity email or phone the caller is authenticating as
     * @param mixed       $code     the submitted verification code
     */
    protected static function isReviewAccountBypass(?string $identity, $code): bool
    {
        return AppReview::configuredAccountMatches($identity, $code);
    }

    /**
     * Whether a code signs an app reviewer in: this storefront's App Review code for the
     * review number, or the instance-wide bypass for a configured review account.
     */
    protected static function acceptsReviewerCode(?string $identity, mixed $code, Store|Network|null $about): bool
    {
        return AppReview::acceptsCode($about, $identity, $code) || static::isReviewAccountBypass($identity, $code);
    }

    /**
     * The answer to a request to send a code to an app reviewer, or null for anyone else.
     * Nothing is ever sent to a reviewer: they sign in with the code the store gave the app
     * store. The review number is refused outright where its access is off, so it is never
     * texted either.
     */
    protected static function reviewerSendResponse(?string $identity, Store|Network|null $about, array $ok)
    {
        if (AppReview::isConfiguredReviewAccount($identity)) {
            return response()->json($ok);
        }

        if (AppReview::isReviewPhone($identity)) {
            return AppReview::isEnabledFor($about) ? response()->json($ok) : response()->apiError(AppReview::OFF_MESSAGE, 422);
        }

        return null;
    }

    /**
     * The key wrong codes are counted under. The review number is shared by every store, so
     * its count is kept per storefront: guessing at one store cannot lock reviewers out of
     * another.
     */
    protected static function attemptIdentity(string $identity, Store|Network|null $about): string
    {
        return AppReview::isReviewPhone($identity) && $about ? $identity . '|' . $about->uuid : $identity;
    }

    public function verifyCode(Request $request)
    {
        $identity = Utils::isEmail($request->identity) ? $request->identity : static::phone($request->identity);
        $code     = $request->input('code');
        $for      = $request->input('for', 'storefront_login');
        $attrs    = $request->input(['name', 'phone', 'email']);

        if ($for === 'storefront_create_customer') {
            return $this->create($this->createCustomerRequestFrom($request));
        }

        // Without an identity there is nobody to verify. The lookup below would compile to
        // `phone IS NULL OR email IS NULL` and pick an arbitrary user to test the code
        // against.
        if (blank($identity)) {
            return response()->apiError('Unable to verify code.');
        }

        // get the storefront or network logging in for
        $about = Storefront::about(['company_uuid']);

        $attemptKey = 'verify|' . static::attemptIdentity($identity, $about);
        if ($this->codeAttemptsExceeded($attemptKey)) {
            return response()->apiError('Too many attempts. Try again later.', 429);
        }

        // This storefront's App Review code signs the review number in, creating the shared
        // review account the first time.
        $isAppReviewer = AppReview::acceptsCode($about, $identity, $code);

        // check if user exists
        $user = $isAppReviewer ? AppReview::user($about) : User::where('phone', $identity)->orWhere('email', $identity)->first();

        if (!$user) {
            $this->recordFailedCodeAttempt($attemptKey);

            return response()->apiError('Unable to verify code.');
        }

        // find and verify code: the newest live code for this user, checked and used up
        if (!$isAppReviewer && !$this->verifySubjectCode($for, $user->uuid, $code) && !static::isReviewAccountBypass($identity, $code)) {
            $this->recordFailedCodeAttempt($attemptKey);

            return response()->apiError('Invalid verification code!');
        }

        $this->clearCodeAttempts($attemptKey);

        // get contact record
        $contact = Contact::firstOrCreate(
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $about->company_uuid,
                'type'         => 'customer',
            ],
            [
                'user_uuid'    => $user->uuid,
                'company_uuid' => $about->company_uuid,
                'name'         => $attrs['name'] ?? $user->name,
                'phone'        => $attrs['phone'] ?? $user->phone,
                'email'        => $attrs['email'] ?? $user->email,
                'type'         => 'customer',
            ]
        );

        // generate auth token
        try {
            $token = $user->createToken($contact->uuid);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        $contact->token = $token->plainTextToken;

        return new Customer($contact);
    }

    protected function verifyAppleIdentity(string $identityToken): bool
    {
        return AppleVerifier::verifyAppleJwt($identityToken);
    }

    protected function verifyGoogleIdentity(string $idToken, string $clientId): ?array
    {
        return GoogleVerifier::verifyIdToken($idToken, $clientId);
    }

    /**
     * Patches phone number with international code.
     */
    public static function phone(?string $phone = null): ?string
    {
        if ($phone === null) {
            $phone = request()->input('phone');
        }

        // With nothing to format this used to return a bare '+', which was then written
        // into contacts.phone and users.phone for every customer created without one, and
        // used as a verification-code lookup key that could never match.
        if (blank($phone)) {
            return null;
        }

        if (!Str::startsWith($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    /**
     * Whether an account already exists for an identity. Anything that goes wrong looking
     * counts as "no", so a bare install without the table cannot block signups.
     */
    protected static function accountExistsForIdentity(?string $identity, bool $isEmail): bool
    {
        if (blank($identity)) {
            return false;
        }

        try {
            return User::where($isEmail ? 'email' : 'phone', $identity)->whereNull('deleted_at')->withoutGlobalScopes()->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether a creation code went to this identity moments ago and is still valid.
     */
    protected static function hasRecentCreationCode(?string $identity): bool
    {
        $seconds = (int) config('storefront.verification.resend_seconds', static::CREATION_CODE_RESEND_SECONDS);
        if (blank($identity) || $seconds <= 0) {
            return false;
        }

        try {
            return VerificationCode::where('for', 'storefront_create_customer')
                ->where('meta->identity', $identity)
                ->where('created_at', '>', now()->subSeconds($seconds))
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Why a phone number cannot be texted, or null when it can. Null too when the core in
     * use has no SMS guard yet, or the guard is switched off: this is the same rule the
     * guard applies on the send, surfaced before anything is written.
     */
    protected static function phoneRejection(?string $phone): ?string
    {
        if (blank($phone) || !class_exists(\Fleetbase\Support\Sms\PhoneValidator::class) || !class_exists(\Fleetbase\Support\Sms\SmsPolicy::class)) {
            return null;
        }

        $settings = \Fleetbase\Support\Sms\SmsPolicy::settings();
        if (!$settings['enabled']) {
            return null;
        }

        $result = \Fleetbase\Support\Sms\PhoneValidator::validate($phone, $settings['validation']);

        return $result['valid'] ? null : \Fleetbase\Support\Sms\PhoneValidator::messageFor($result['reason']);
    }

    /**
     * The response for a send the SMS guard refused: its own status (422 bad number, 429
     * limited, 423 paused) and Retry-After when there is a time to wait.
     */
    protected static function blockedResponse(SmsBlockedException $e)
    {
        $response = response()->apiError($e->getMessage(), $e->status());
        $response->headers->add($e->headers());

        return $response;
    }

    /**
     * The response when a code could not be sent: the guard's refusal when that is why,
     * otherwise the flow's own generic message.
     */
    protected static function sendFailureResponse(?\Throwable $failure, string $message)
    {
        if ($failure instanceof SmsBlockedException) {
            return static::blockedResponse($failure);
        }

        return response()->apiError($message);
    }

    /**
     * Check a code filed under an identity and use it up. The newest live code is the one
     * that counts; a wrong guess counts against it until it locks.
     */
    protected function verifyIdentityCode(string $for, string $identity, mixed $code): bool
    {
        $verificationCode = VerificationCode::findActiveFor($for, $identity);

        return $verificationCode !== null && $this->checkAndConsume($verificationCode, $code);
    }

    /**
     * The live code for a subject once the submitted code matched it (and used it up), else null.
     */
    protected function findSubjectCode(string $for, string $subjectUuid, mixed $code): ?VerificationCode
    {
        $verificationCode = VerificationCode::findActiveForSubject($for, $subjectUuid);

        return $verificationCode !== null && $this->checkAndConsume($verificationCode, $code) ? $verificationCode : null;
    }

    protected function verifySubjectCode(string $for, string $subjectUuid, mixed $code): bool
    {
        return $this->findSubjectCode($for, $subjectUuid, $code) !== null;
    }

    protected function checkAndConsume(VerificationCode $verificationCode, mixed $code): bool
    {
        if ($verificationCode->check((string) $code, static::CODE_CHECK_ATTEMPTS) !== VerificationCode::CHECK_VALID) {
            return false;
        }

        $verificationCode->consume();

        return true;
    }

    protected function codeAttemptsExceeded(string $key): bool
    {
        $limiter = $this->attemptLimiter();

        return $limiter ? $limiter->tooManyAttempts($this->codeAttemptKey($key), static::CODE_ATTEMPT_LIMIT) : false;
    }

    protected function recordFailedCodeAttempt(string $key): void
    {
        $this->attemptLimiter()?->hit($this->codeAttemptKey($key), static::CODE_ATTEMPT_WINDOW);
    }

    protected function clearCodeAttempts(string $key): void
    {
        $this->attemptLimiter()?->clear($this->codeAttemptKey($key));
    }

    protected function codeAttemptKey(string $key): string
    {
        return 'storefront-code-attempts:' . sha1(strtolower($key));
    }

    /**
     * The limiter behind the attempt lockout, or null where none is bound (unit tests).
     */
    protected function attemptLimiter(): ?RateLimiter
    {
        try {
            return app()->bound(RateLimiter::class) ? app(RateLimiter::class) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getStripeEphemeralKey(Request $request)
    {
        $customer = Storefront::getCustomerFromToken();
        if (!$customer) {
            return response()->apiError('Not authorized to view customers places');
        }

        $gateway    = Storefront::findGateway('stripe');
        if (!$gateway) {
            return response()->apiError('Stripe not setup.');
        }

        \Stripe\Stripe::setApiKey($gateway->config->secret_key);

        try {
            // Ensure customer has a stripe_id. Inside the try: Stripe refusing the gateway's key
            // must come back as an error, not an uncaught exception rendered as 401.
            if ($customer->missingMeta('stripe_id')) {
                Storefront::createStripeCustomerForContact($customer);
            }

            // Create Ephemeral Key
            $ephemeralKey = \Stripe\EphemeralKey::create(
                ['customer' => $customer->getMeta('stripe_id')],
                ['stripe_version' => '2020-08-27']
            );

            return response()->json([
                'ephemeralKey'            => $ephemeralKey->secret,
                'customerId'              => $customer->getMeta('stripe_id'),
            ]);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }
    }

    public function getStripeSetupIntent(Request $request)
    {
        $customer = Storefront::getCustomerFromToken();
        if (!$customer) {
            return response()->apiError('Not authorized to view customers places');
        }

        $gateway    = Storefront::findGateway('stripe');
        if (!$gateway) {
            return response()->apiError('Stripe not setup.');
        }

        \Stripe\Stripe::setApiKey($gateway->config->secret_key);

        try {
            // Ensure customer has a stripe_id. Inside the try: Stripe refusing the gateway's key
            // must come back as an error, not an uncaught exception rendered as 401.
            if ($customer->missingMeta('stripe_id')) {
                Storefront::createStripeCustomerForContact($customer);
            }

            // Create SetupIntent
            $setupIntent = \Stripe\SetupIntent::create([
                'customer' => $customer->getMeta('stripe_id'),
            ]);

            return response()->json([
                'setupIntentId'          => $setupIntent->id,
                'setupIntent'            => $setupIntent->client_secret,
                'customerId'             => $customer->getMeta('stripe_id'),
            ]);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }
    }

    public function startAccountClosure(Request $request)
    {
        $about    = Storefront::about(['company_uuid']);
        if (!$about) {
            return response()->apiError('Storefront not found.');
        }

        $customer = Storefront::getCustomerFromToken();
        if (!$customer) {
            return response()->apiError('Not authorized to view customers places');
        }

        // Get the user account for the contact/customer
        $user = User::where(['uuid' => $customer->user_uuid])->first();
        if (!$user) {
            return response()->apiError('Customer user account not found.');
        }

        // Check for phone or email
        if (!$user->phone && !$user->email) {
            return response()->apiError('Customer account must have a valid email or phone number linked.');
        }

        // The identity the code is filed under MUST match what confirmAccountClosure looks
        // it up by — `$user->phone ?? $user->email` — regardless of which channel actually
        // carried it. Previously SMS filed it under the phone and email under the email,
        // which agreed only because the channel was chosen by the same precedence; the
        // fallback below breaks that coupling, so it is made explicit.
        $identity        = $user->phone ?? $user->email;
        $messageCallback = function ($verification) use ($about) {
            return "Your {$about->name} account closure verification code is {$verification->code}";
        };

        // An app reviewer confirms with the store's App Review code; nothing is sent.
        if ($reviewerResponse = static::reviewerSendResponse($identity, $about, ['status' => 'OK'])) {
            return $reviewerResponse;
        }

        // The SMS attempt is guarded rather than sharing one try with the email branch.
        // The Twilio SDK throws when the store has no credentials configured, and the old
        // `if phone / elseif email` meant a customer WITH a phone never reached the email
        // branch — the request just returned the SDK's own message, "Credentials are
        // required to create a Client", to the client.
        $sent       = false;
        $smsFailure = null;

        if ($user->phone) {
            try {
                VerificationCode::generateSmsVerificationFor($user, 'storefront_account_closure', [
                    'hashed'          => true,
                    'messageCallback' => $messageCallback,
                    'meta'            => ['identity' => $identity],
                ]);
                $sent = true;
            } catch (\Throwable $e) {
                $smsFailure = $e;

                if (app()->bound('sentry')) {
                    app('sentry')->captureException($e);
                }
            }
        }

        if (!$sent && $user->email) {
            try {
                VerificationCode::generateEmailVerificationFor($user, 'storefront_account_closure', [
                    'subject'         => $about->name . ' account closure request',
                    'messageCallback' => $messageCallback,
                    'meta'            => ['identity' => $identity],
                ]);
                $sent = true;
            } catch (\Throwable $e) {
                if (app()->bound('sentry')) {
                    app('sentry')->captureException($e);
                }
            }
        }

        if ($sent) {
            return response()->json(['status' => 'OK']);
        }

        return static::sendFailureResponse($smsFailure, 'Unable to send account closure verification code.');
    }

    public function confirmAccountClosure(Request $request)
    {
        $code     = $request->input('code');
        $about    = Storefront::about(['company_uuid']);
        if (!$about) {
            return response()->apiError('Storefront not found.');
        }

        $customer = Storefront::getCustomerFromToken();
        if (!$customer) {
            return response()->apiError('Not authorized to view customers places');
        }

        // Get the user account for the contact/customer
        $user = User::where(['uuid' => $customer->user_uuid])->first();
        if (!$user) {
            return response()->apiError('Customer user account not found.');
        }

        // Get verification identity
        $identity = $user->phone ?? $user->email;

        if ($this->codeAttemptsExceeded('closure|' . $customer->uuid)) {
            return response()->apiError('Too many attempts. Try again later.', 429);
        }

        // verify account closure code
        $isAppReviewer = AppReview::acceptsCode($about, $identity, $code);
        if (!$isAppReviewer && !$this->verifyIdentityCode('storefront_account_closure', $identity, $code) && !static::isReviewAccountBypass($identity, $code)) {
            $this->recordFailedCodeAttempt('closure|' . $customer->uuid);

            return response()->apiError('Invalid verification code provided!');
        }

        $this->clearCodeAttempts('closure|' . $customer->uuid);

        try {
            // If the user type is `contact` or `customer` delete the user account. The review
            // account is shared by every store, so closing it only removes this store's
            // customer; it signs in afresh next time.
            if ($user->isType(['contact', 'customer']) && !AppReview::isReviewPhone($user->phone)) {
                $user->delete();
            }

            // Delete the customer
            $customer->delete();

            return response()->json(['status' => 'OK']);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        return response()->apiError('An uknown error occured attempting to close customer account.');
    }

    /**
     * Sends a verification code to the customer's phone for verification.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function requestPhoneVerification(Request $request)
    {
        $customer = Storefront::getCustomerFromToken();
        $phone    = static::phone($request->input('phone'));

        if (!$customer) {
            return response()->apiError('Not authorized to request phone verification.');
        }

        // Use the user associated with the contact
        $user = User::where(['uuid' => $customer->user_uuid])->first();
        if (!$user) {
            return response()->apiError('No user associated with this customer.');
        }

        // No phone to verify. This used to arrive as the literal '+' and get as far as the
        // SMS provider before failing with a credentials error.
        if (!$phone) {
            return response()->apiError('A phone number is required to request verification.');
        }

        $about = Storefront::about();

        // Check if phone number is already used by another user. The review number is the
        // exception: it belongs to the shared review account, and a reviewer signed in another
        // way (Apple, Google) may still be asked for it at checkout.
        $existingUser = AppReview::isReviewPhone($phone) ? null : $this->findExistingUserByPhone($phone, $user->uuid);

        if ($existingUser) {
            return response()->apiError('This phone number is already associated with another account.');
        }

        // An app reviewer verifies with the store's App Review code (or a self-hosted review
        // account with the configured bypass code), so no message needs to be delivered —
        // and requiring one would make the flow untestable wherever SMS is not configured,
        // which is the situation review access exists for.
        if ($reviewerResponse = static::reviewerSendResponse($phone, $about, ['status' => 'ok', 'method' => 'bypass'])) {
            return $reviewerResponse;
        }

        // A number that can never receive a text is refused before anything is written.
        if ($rejection = static::phoneRejection($phone)) {
            return response()->apiError($rejection, 422);
        }

        try {
            // Text the number being verified, not the one on file (which may be none).
            VerificationCode::generateSmsVerificationFor($user, 'storefront_verify_phone', [
                'hashed'          => true,
                'phone'           => $phone,
                'messageCallback' => function ($verification) use ($about) {
                    return "Your {$about->name} verification code is {$verification->code}";
                },
                'meta' => ['phone' => $phone], // Store the phone number in meta
            ]);

            return response()->json(['status' => 'ok']);
        } catch (SmsBlockedException $e) {
            return static::blockedResponse($e);
        } catch (\Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }

            // Deliberately not $e->getMessage(): the Twilio SDK throws
            // "Credentials are required to create a Client" when the store has no SMS
            // credentials, and returning that to an API consumer leaks an internal
            // detail while telling them nothing they can act on. Unlike customer login
            // and account closure, there is no email fallback here — verifying a phone
            // number by email would not verify anything.
            return response()->apiError('Unable to send phone verification code.');
        }
    }

    protected function findExistingUserByPhone(string $phone, string $excludedUserUuid): ?User
    {
        return User::where('phone', $phone)
            ->where('uuid', '!=', $excludedUserUuid)
            ->whereNull('deleted_at')
            ->withoutGlobalScopes()
            ->first();
    }

    /**
     * Verifies the phone number using the provided code.
     *
     * @return Customer
     */
    public function verifyPhoneNumber(Request $request)
    {
        $customer = Storefront::getCustomerFromToken();
        $code     = $request->input('code');

        if (!$customer) {
            return response()->apiError('Not authorized to verify phone number.');
        }

        $user = $customer->user;

        if (!$user) {
            return response()->apiError('No user associated with this customer.');
        }

        if ($this->codeAttemptsExceeded('verify-phone|' . $user->uuid)) {
            return response()->apiError('Too many attempts. Try again later.', 429);
        }

        // Find the verification code: the newest live one for this user, checked and used up
        $verificationCode = $this->findSubjectCode('storefront_verify_phone', $user->uuid, $code);

        // The bypass leaves no code row to read the phone back from, so it comes from the
        // request — which is what the caller is asking to verify in the first place.
        $requestedPhone = $request->input('phone') ? static::phone($request->input('phone')) : null;

        if (!$verificationCode) {
            if ($requestedPhone && static::acceptsReviewerCode($requestedPhone, $code, Storefront::about())) {
                $user->update(['phone' => $requestedPhone, 'phone_verified_at' => now()]);
                $customer->update(['phone' => $requestedPhone]);

                return new Customer($customer->fresh());
            }

            $this->recordFailedCodeAttempt('verify-phone|' . $user->uuid);

            return response()->apiError('Invalid verification code!');
        }

        $this->clearCodeAttempts('verify-phone|' . $user->uuid);

        // Get the phone number from meta. A row written by anything other than
        // requestPhoneVerification may not carry it, and an unguarded subscript turns a
        // recoverable 400 into a 500.
        $phone = data_get($verificationCode->meta, 'phone');
        if (!$phone) {
            return response()->apiError('Verification code is not associated with a phone number.');
        }

        // Update user and contact
        $user->update(['phone' => $phone, 'phone_verified_at' => now()]);
        $customer->update(['phone' => $phone]);

        // The code was used up by findSubjectCode(), so it cannot verify twice.
        return new Customer($customer->fresh());
    }
}
