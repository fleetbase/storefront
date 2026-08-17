<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\Auth\AppleVerifier;
use Fleetbase\Auth\GoogleVerifier;
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
use Fleetbase\Storefront\Support\Storefront;
use Fleetbase\Support\Utils;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
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

        $device = UserDevice::firstOrCreate(
            [
                'token'    => $request->input('token'),
                'platform' => $request->or(['platform', 'os']),
            ],
            [
                'user_uuid' => $customer->user_uuid,
                'platform'  => $request->or(['platform', 'os']),
                'token'     => $request->input('token'),
                'status'    => 'active',
            ]
        );

        return response()->json([
            'status' => 'OK',
            'device' => $device->public_id,
        ]);
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

            // dont query any master orders if its a network
            if (session('storefront_network')) {
                $query->where(function ($q) {
                    $q->where('meta->is_master_order', false);
                    $q->orWhere('meta', 'not like', '%related_orders%');
                });
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
                    'messageCallback' => function ($verification) use ($about) {
                        return "Your {$about->name} verification code is {$verification->code}";
                    },
                    'meta' => $meta,
                ]);
            }

            return response()->json(['status' => 'ok']);
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

        // verify code
        $verificationCode = VerificationCode::where(['code' => $code, 'for' => 'storefront_create_customer', 'meta->identity' => $identity])->exists();
        if (!$verificationCode) {
            return response()->apiError('Invalid verification code provided!');
        }

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
                    'phone'        => static::phone($request->input('phone')),
                ],
                $request->only(['name', 'email', 'phone', 'meta'])
            ));
            $user->setUserType('customer');
        } elseif (!$user->type) {
            $user->setUserType('customer');
        }

        // always customer type
        $input['type']         = 'customer';
        $input['company_uuid'] = session('company');
        $input['phone']        = static::phone($request->input('phone'));
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
        if (Str::startsWith($id, 'customer')) {
            $id = Str::replaceFirst('customer', 'contact', $id);
        }

        // find for the contact
        try {
            $contact = Contact::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->apiError('Customer resource not found.');
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
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function query(Request $request)
    {
        $results = Contact::queryWithRequestCached($request, function (&$query, $request) {
            $query->where(['type' => 'customer', 'company_uuid' => session('company')]);
        });

        return Customer::collection($results);
    }

    /**
     * Finds a single Storefront Product resources.
     *
     * @return \Fleetbase\Http\Resources\Storefront\Customer
     */
    public function find($id)
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

        // check if user exists
        $user = User::where('phone', $phone)->whereNull('deleted_at')->withoutGlobalScopes()->first();

        if (!$user) {
            return response()->apiError('No customer with this phone # found.');
        }

        // get the storefront or network logging in for
        $about = Storefront::about();

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

        try {
            VerificationCode::generateSmsVerificationFor($user, 'storefront_login', [
                'messageCallback' => $messageCallback,
            ]);

            return response()->json(['status' => 'OK', 'method' => 'sms']);
        } catch (\Throwable $e) {
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

        return response()->apiError('Unable to send verification code.');
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
     * Whether a verification code should be accepted as an app-review bypass.
     *
     * App store reviewers cannot receive our SMS or email, so a fixed code has to keep
     * working in production. It used to be compared against the submitted code alone,
     * which meant anyone who learned it could authenticate as ANY customer. It is now
     * only honoured for an identity explicitly listed in storefront.review_accounts,
     * and both the code and the list must be configured.
     *
     * @param string|null $identity email or phone the caller is authenticating as
     * @param mixed       $code     the submitted verification code
     */
    protected static function isReviewAccountBypass(?string $identity, $code): bool
    {
        $bypassCode = config('storefront.storefront_app.bypass_verification_code');
        if (blank($bypassCode) || blank($code) || blank($identity)) {
            return false;
        }

        $reviewAccounts = array_map(
            static fn ($account) => strtolower(trim((string) $account)),
            (array) config('storefront.storefront_app.review_accounts', [])
        );

        if (!in_array(strtolower(trim($identity)), $reviewAccounts, true)) {
            return false;
        }

        // hash_equals so a wrong code cannot be recovered by timing the response.
        if (!hash_equals((string) $bypassCode, (string) $code)) {
            return false;
        }

        Log::warning('[Storefront] Verification bypass accepted for a review account.', [
            'identity' => $identity,
        ]);

        return true;
    }

    public function verifyCode(Request $request)
    {
        $identity = Utils::isEmail($request->identity) ? $request->identity : static::phone($request->identity);
        $code     = $request->input('code');
        $for      = $request->input('for', 'storefront_login');
        $attrs    = $request->input(['name', 'phone', 'email']);

        if ($for === 'storefront_create_customer') {
            return $this->create($request);
        }

        // Without an identity there is nobody to verify. The lookup below would compile to
        // `phone IS NULL OR email IS NULL` and pick an arbitrary user to test the code
        // against.
        if (blank($identity)) {
            return response()->apiError('Unable to verify code.');
        }

        // check if user exists
        $user = User::where('phone', $identity)->orWhere('email', $identity)->first();

        if (!$user) {
            return response()->apiError('Unable to verify code.');
        }

        // find and verify code
        $verificationCode = VerificationCode::where(['subject_uuid' => $user->uuid, 'code' => $code, 'for' => $for])->exists();
        if (!$verificationCode && !static::isReviewAccountBypass($identity, $code)) {
            return response()->apiError('Invalid verification code!');
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

        // Ensure customer has a stripe_id
        if ($customer->missingMeta('stripe_id')) {
            Storefront::createStripeCustomerForContact($customer);
        }

        try {
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

        // Ensure customer has a stripe_id
        if ($customer->missingMeta('stripe_id')) {
            Storefront::createStripeCustomerForContact($customer);
        }

        try {
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

        // The SMS attempt is guarded rather than sharing one try with the email branch.
        // The Twilio SDK throws when the store has no credentials configured, and the old
        // `if phone / elseif email` meant a customer WITH a phone never reached the email
        // branch — the request just returned the SDK's own message, "Credentials are
        // required to create a Client", to the client.
        $sent = false;

        if ($user->phone) {
            try {
                VerificationCode::generateSmsVerificationFor($user, 'storefront_account_closure', [
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

        return response()->apiError('Unable to send account closure verification code.');
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

        // verify account closure code
        $verificationCode = VerificationCode::where(['code' => $code, 'for' => 'storefront_account_closure', 'meta->identity' => $identity])->exists();
        if (!$verificationCode && !static::isReviewAccountBypass($identity, $code)) {
            return response()->apiError('Invalid verification code provided!');
        }

        try {
            // If the user type is `contact` or `customer` delete the user account
            if ($user->isType(['contact', 'customer'])) {
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

        // Check if phone number is already used by another user
        $existingUser = $this->findExistingUserByPhone($phone, $user->uuid);

        if ($existingUser) {
            return response()->apiError('This phone number is already associated with another account.');
        }

        $about = Storefront::about();

        // A review account verifies with the configured bypass code, so no message needs
        // to be delivered — and requiring one would make the flow untestable wherever SMS
        // is not configured, which is the situation this bypass exists for. Same
        // allowlist + constant-time code check as every other bypass call site.
        if (static::isReviewAccountBypass($phone, config('storefront.storefront_app.bypass_verification_code'))) {
            return response()->json(['status' => 'ok', 'method' => 'bypass']);
        }

        try {
            VerificationCode::generateSmsVerificationFor($user, 'storefront_verify_phone', [
                'messageCallback' => function ($verification) use ($about) {
                    return "Your {$about->name} verification code is {$verification->code}";
                },
                'meta' => ['phone' => $phone], // Store the phone number in meta
            ]);

            return response()->json(['status' => 'ok']);
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

        // Find the verification code
        $verificationCode = VerificationCode::where([
            'subject_uuid' => $user->uuid,
            'code'         => $code,
            'for'          => 'storefront_verify_phone',
        ])->first();

        // The bypass leaves no code row to read the phone back from, so it comes from the
        // request — which is what the caller is asking to verify in the first place.
        $requestedPhone = $request->input('phone') ? static::phone($request->input('phone')) : null;

        if (!$verificationCode) {
            if ($requestedPhone && static::isReviewAccountBypass($requestedPhone, $code)) {
                $user->update(['phone' => $requestedPhone, 'phone_verified_at' => now()]);
                $customer->update(['phone' => $requestedPhone]);

                return new Customer($customer->fresh());
            }

            return response()->apiError('Invalid verification code!');
        }

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

        // Invalidate the verification code
        $verificationCode->delete();

        return new Customer($customer->fresh());
    }
}
