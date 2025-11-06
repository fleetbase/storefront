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
        });

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
        });

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
        $identity = $request->input('identity');
        $user     = null;

        if (!Utils::isEmail($identity)) {
            $identity = static::phone($identity);
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
                    'type'         => 'customer',
                    'company_uuid' => session('company'),
                    'phone'        => static::phone($request->input('phone')),
                ],
                $request->only(['name', 'type', 'email', 'phone', 'meta'])
            ));
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
        try {
            $customer = Contact::create($input);
        } catch (UserAlreadyExistsException $e) {
            // If the exception is thrown because user already exists and
            // that user is the same user already assigned continue
            $customer = Contact::where(['company_uuid' => session('company'), 'phone' => $input['phone']])->first();
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
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
        $results = Contact::queryWithRequest($request, function (&$query, $request) {
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

        $user = User::where('email', $identity)->orWhere('phone', static::phone($identity))->first();

        if (!Hash::check($password, $user->password)) {
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

        // check if user exists
        $user = User::where('phone', $phone)->whereNull('deleted_at')->withoutGlobalScopes()->first();

        if (!$user) {
            return response()->apiError('No customer with this phone # found.');
        }

        // get the storefront or network logging in for
        $about = Storefront::about();

        // generate verification token
        VerificationCode::generateSmsVerificationFor($user, 'storefront_login', [
            'messageCallback' => function ($verification) use ($about) {
                return "Your {$about->name} verification code is {$verification->code}";
            },
        ]);

        return response()->json(['status' => 'OK']);
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
            // Verify the Apple token using the utility function
            $isValid = AppleVerifier::verifyAppleJwt($identityToken);
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
            $payload = GoogleVerifier::verifyIdToken($idToken, $clientId);
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
    public function verifyCode(Request $request)
    {
        $identity = Utils::isEmail($request->identity) ? $request->identity : static::phone($request->identity);
        $code     = $request->input('code');
        $for      = $request->input('for', 'storefront_login');
        $attrs    = $request->input(['name', 'phone', 'email']);

        if ($for === 'storefront_create_customer') {
            return $this->create($request);
        }

        // check if user exists
        $user = User::where('phone', $identity)->orWhere('email', $identity)->first();

        if (!$user) {
            return response()->apiError('Unable to verify code.');
        }

        // find and verify code
        $verificationCode = VerificationCode::where(['subject_uuid' => $user->uuid, 'code' => $code, 'for' => $for])->exists();
        if (!$verificationCode && $code !== config('storefront.storefront_app.bypass_verification_code')) {
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

    /**
     * Patches phone number with international code.
     */
    public static function phone(?string $phone = null): string
    {
        if ($phone === null) {
            $phone = request()->input('phone');
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

        // Send account closure confirmation with code
        try {
            if ($user->phone) {
                VerificationCode::generateSmsVerificationFor($user, 'storefront_account_closure', [
                    'messageCallback' => function ($verification) use ($about) {
                        return "Your {$about->name} account closure verification code is {$verification->code}";
                    },
                    'meta' => ['identity' => $user->phone],
                ]);
            } elseif ($user->email) {
                VerificationCode::generateEmailVerificationFor($user, 'storefront_account_closure', [
                    'subject'         => $about->name . ' account closure request',
                    'messageCallback' => function ($verification) use ($about) {
                        return "Your {$about->name} account closure verification code is {$verification->code}";
                    },
                    'meta' => ['identity' => $user->email],
                ]);
            }

            return response()->json(['status' => 'OK']);
        } catch (\Exception $e) {
            return response()->apiError($e->getMessage());
        }

        return response()->apiError('An uknown error occured attempting to close customer account.');
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
        if (!$verificationCode && $code !== config('storefront.storefront_app.bypass_verification_code')) {
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
}
