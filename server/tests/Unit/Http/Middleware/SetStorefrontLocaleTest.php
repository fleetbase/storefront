<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Storefront\Http\Middleware\SetStorefrontLocale;
use Fleetbase\Storefront\Notifications\StorefrontOrderAccepted;
use Fleetbase\Storefront\Notifications\StorefrontOrderChatMessage;
use Fleetbase\Storefront\Notifications\StorefrontOrderNearby;
use Fleetbase\Storefront\Notifications\StorefrontOrderUpdated;
use Fleetbase\Storefront\Support\CustomerLocale;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Request;

function localeTestSchema(array $meta = ['locale' => 'en']): void
{
    $schema = Capsule::schema('mysql');
    $schema->dropIfExists('contacts');
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('type')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Capsule::connection('mysql')->table('contacts')->insert([
        'uuid'      => 'locale_contact_uuid',
        'public_id' => 'contact_locale',
        'name'      => 'Bat',
        'type'      => 'customer',
        'meta'      => json_encode($meta),
    ]);

    $schema->dropIfExists('stores');
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id');
        $table->string('company_uuid')->nullable();
        $table->string('name');
        $table->string('currency')->nullable();
        $table->string('timezone')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Capsule::connection('mysql')->table('stores')->insert([
        'uuid'      => 'locale_store_uuid',
        'public_id' => 'store_locale',
        'name'      => 'Nomin',
        'currency'  => 'MNT',
        'timezone'  => 'Asia/Ulaanbaatar',
        'options'   => '{}',
    ]);
}

function localeTestStoredLocale(): ?string
{
    return data_get(json_decode((string) Capsule::connection('mysql')->table('contacts')->where('uuid', 'locale_contact_uuid')->value('meta'), true), 'locale');
}

function localeTestOrder(?string $locale, array $meta = []): Order
{
    $customer = new Contact();
    $customer->forceFill(['uuid' => 'locale_contact_uuid', 'meta' => $locale ? ['locale' => $locale] : []]);

    $order = new Order();
    $order->forceFill([
        'uuid'      => 'locale_order_uuid',
        'public_id' => 'order_locale',
        'meta'      => ['storefront_id' => 'store_locale', 'currency' => 'MNT', ...$meta],
    ]);
    $order->setRelation('customer', $customer);

    return $order;
}

function localeTestRequest(?string $language, bool $signedIn = false): Request
{
    $server = $language === null ? [] : ['HTTP_ACCEPT_LANGUAGE' => $language];
    if ($signedIn) {
        $server['HTTP_CUSTOMER_TOKEN'] = 'customer_token';
    }

    $request = Request::create('/storefront/v1/checkouts/before', 'GET', [], [], [], $server);
    if ($language === null) {
        // Request::create() adds an English Accept-Language of its own.
        $request->headers->remove('Accept-Language');
    }

    return $request;
}

afterEach(function () {
    session(['customer' => null]);
    app()->setLocale('en');
});

test('storefront supports the languages it ships, english first', function () {
    expect(CustomerLocale::available())->toBe(['en', 'mn', 'uk'])
        ->and(CustomerLocale::supports('mn'))->toBeTrue()
        ->and(CustomerLocale::supports('fr'))->toBeFalse()
        ->and(CustomerLocale::supports(null))->toBeFalse();
});

test('every language has the same messages and placeholders as english', function () {
    $flatten = function (array $lines, string $prefix = '') use (&$flatten): array {
        $flat = [];
        foreach ($lines as $key => $value) {
            $flat = is_array($value) ? [...$flat, ...$flatten($value, $prefix . $key . '.')] : [...$flat, $prefix . $key => $value];
        }

        return $flat;
    };
    $placeholders = function (string $line): array {
        preg_match_all('/:([a-z_]+)/', $line, $matches);
        $names = array_unique($matches[1]);
        sort($names);

        return $names;
    };

    foreach (glob(CustomerLocale::path() . '/en/*.php') as $file) {
        $english = $flatten(require $file);

        foreach (CustomerLocale::available() as $locale) {
            $translated = $flatten(require CustomerLocale::path() . '/' . $locale . '/' . basename($file));

            expect(array_keys($translated))->toEqualCanonicalizing(array_keys($english), $locale . '/' . basename($file));
            foreach ($english as $key => $line) {
                expect($placeholders($translated[$key]))->toBe($placeholders($line), $locale . '/' . basename($file) . ' ' . $key);
            }
        }
    }
});

test('the request language picks the closest supported language', function () {
    expect(CustomerLocale::fromRequest(localeTestRequest(null)))->toBeNull()
        ->and(CustomerLocale::fromRequest(localeTestRequest('mn')))->toBe('mn')
        ->and(CustomerLocale::fromRequest(localeTestRequest('mn-MN,en;q=0.5')))->toBe('mn')
        ->and(CustomerLocale::fromRequest(localeTestRequest('uk-UA')))->toBe('uk')
        ->and(CustomerLocale::fromRequest(localeTestRequest('en-US')))->toBe('en')
        ->and(CustomerLocale::fromRequest(localeTestRequest('fr')))->toBe('en');
});

test('a customer language is read from their meta when it is supported', function () {
    $customer = new Contact();

    expect(CustomerLocale::for(null))->toBeNull()
        ->and(CustomerLocale::for(new stdClass()))->toBeNull()
        ->and(CustomerLocale::for($customer->forceFill(['meta' => []])))->toBeNull()
        ->and(CustomerLocale::for($customer->forceFill(['meta' => ['locale' => 'fr']])))->toBeNull()
        ->and(CustomerLocale::for($customer->forceFill(['meta' => ['locale' => 'mn']])))->toBe('mn');
});

test('the middleware answers in the request language and restores the previous one', function () {
    $middleware = new SetStorefrontLocale();
    $seen       = null;
    $next       = function () use (&$seen) {
        $seen = [app()->getLocale(), __('storefront::notifications.order_nearby.subject')];

        return response()->json(['ok' => true]);
    };

    $middleware->handle(localeTestRequest(null), $next);
    expect($seen)->toBe(['en', 'Your order is nearby!']);

    $response = $middleware->handle(localeTestRequest('mn-MN'), $next);
    expect($seen)->toBe(['mn', 'Таны захиалга ойртож байна!'])
        ->and($response->getData(true))->toBe(['ok' => true])
        ->and(app()->getLocale())->toBe('en');
});

test('the middleware remembers the language of a signed-in customer only when it changed', function () {
    localeTestSchema();
    $middleware = new SetStorefrontLocale();
    $next       = fn () => response()->json(['ok' => true]);

    // A guest, or a session without a customer token, stores nothing.
    $middleware->handle(localeTestRequest('mn'), $next);
    session(['customer' => 'locale_contact_uuid']);
    $middleware->handle(localeTestRequest('mn'), $next);
    expect(localeTestStoredLocale())->toBe('en');

    $middleware->handle(localeTestRequest('mn', true), $next);
    expect(localeTestStoredLocale())->toBe('mn');

    $middleware->handle(localeTestRequest('mn', true), $next);
    expect(localeTestStoredLocale())->toBe('mn');

    session(['customer' => 'missing_contact_uuid']);
    $middleware->handle(localeTestRequest('uk', true), $next);
    expect(localeTestStoredLocale())->toBe('mn');
});

test('order notifications are written in the customer language', function () {
    localeTestSchema();

    $english   = new StorefrontOrderAccepted(localeTestOrder(null));
    $mongolian = new StorefrontOrderAccepted(localeTestOrder('mn'));
    $ukrainian = new StorefrontOrderAccepted(localeTestOrder('uk'));

    expect($english->locale)->toBeNull()
        ->and($english->subject)->toBe('Your order from Nomin has been accepted.')
        ->and($mongolian->locale)->toBe('mn')
        ->and($mongolian->subject)->toBe('Nomin таны захиалгыг хүлээн авлаа.')
        ->and($mongolian->toPush(null)->body)->toBe('Таны захиалга баталгаажлаа.')
        ->and($ukrainian->subject)->toBe('Nomin прийняв ваше замовлення.');
});

test('times in notifications are written in the customer language', function () {
    localeTestSchema();

    $nearby = new StorefrontOrderNearby(localeTestOrder('uk'), 900, 300);

    expect($nearby->body)->toStartWith('Ваше замовлення з Nomin прибуде через ')
        ->and($nearby->body)->not->toContain('minute');
});

test('an english edit summary is only shown to customers reading english', function () {
    localeTestSchema();

    $english   = new StorefrontOrderUpdated(localeTestOrder('en'), 'Tea 2→1', ['items']);
    $mongolian = new StorefrontOrderUpdated(localeTestOrder('mn'), 'Tea 2→1', ['items']);

    expect($english->body)->toBe('Tea 2→1.')
        ->and($mongolian->subject)->toBe('Nomin-ийн таны захиалга шинэчлэгдлээ')
        ->and($mongolian->body)->toBe('Nomin-ийн таны захиалга шинэчлэгдлээ.');
});

test('a chat message notification is sent in the customer language', function () {
    $notification = new StorefrontOrderChatMessage(new ChatMessage(['content' => '']), localeTestOrder('mn'));

    app()->setLocale($notification->locale);

    expect($notification->locale)->toBe('mn')
        ->and($notification->title())->toBe('Таны жолооч танд мессеж илгээлээ')
        ->and($notification->body())->toBe('Зураг илгээлээ');
});
