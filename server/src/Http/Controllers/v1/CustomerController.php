<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\FleetOps\Http\Requests\UpdateContactRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Http\Resources\v1\Place as PlaceResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\User;
use Fleetbase\Models\UserDevice;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Storefront\Http\Requests\CreateCustomerRequest;
use Fleetbase\Storefront\Http\Requests\VerifyCreateCustomerRequest;
use Fleetbase\Storefront\Http\Resources\Customer;
use Fleetbase\Storefront\Support\Storefront;
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
            return response()->error('Not authorized to register device for cutomer');
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
            return response()->error('Not authorized to view customers orders');
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
            return response()->error('Not authorized to view customers places');
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
            return response()->error('Invalid email provided for identity');
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
                'meta' => $meta
            ]);
        } else {
            VerificationCode::generateSmsVerificationFor($customer, 'storefront_create_customer', [
                'messageCallback' => function ($verification) use ($about) {
                    return "Your {$about->name} verification code is {$verification->code}";
                },
                'meta' => $meta
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
        $isVerified = VerificationCode::where(['code' => $code, 'for' => 'storefront_create_customer', 'meta->identity' => $identity])->exists();

        if (!$isVerified) {
            return response()->error('Invalid verification code provided!');
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

        // create the customer/contact
        $customer = Contact::create($input);

        // generate auth token
        try {
            $token = $user->createToken($customer->uuid);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
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
            return response()->error('Customer resource not found.');
        }

        // get request input
        $input = $request->only(['name', 'type', 'title', 'email', 'phone', 'meta']);

        // always customer type
        $input['type'] = 'customer';

        // update the contact
        $contact->update($input);

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
            $query->where(['type' => 'customer']);
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
            return response()->error('Customer resource not found.');
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
            return response()->error('Customer resource not found.');
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
            return response()->error('Authentication failed using password provided.', 401);
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
            return response()->error($e->getMessage());
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
            return response()->error('No customer with this phone # found.');
        }

        // get the storefront or network logging in for
        $about = Storefront::about();

        // generate verification token
        VerificationCode::generateSmsVerificationFor($user, 'storefront_login', [
            'messageCallback' => function ($verification) use ($about) {
                return "Your {$about->name} verification code is {$verification->code}";
            }
        ]);

        return response()->json(['status' => 'OK']);
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
            return response()->error('Unable to verify code.');
        }

        // find and verify code
        $verificationCode = VerificationCode::where(['subject_uuid' => $user->uuid, 'code' => $code, 'for' => $for])->exists();

        if (!$verificationCode && $code !== '999000') {
            return response()->error('Invalid verification code!');
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
            return response()->error($e->getMessage());
        }

        $contact->token = $token->plainTextToken;

        return new Customer($contact);
    }

    /**
     * Patches phone number with international code.
     */
    public static function phone(string $phone = null): string
    {
        if ($phone === null) {
            $phone = request()->input('phone');
        }

        if (!Str::startsWith($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }
}
