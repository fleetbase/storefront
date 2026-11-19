<?php

require_once __DIR__ . '/../../../Support/PushAppTestHelpers.php';

use Fleetbase\Models\PushApp;
use Fleetbase\Push\PushOutcome;
use Fleetbase\Storefront\Http\Controllers\PushAppController;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Push\StorefrontPushApps;
use Illuminate\Http\Request;

/**
 * Settings → Notifications: the store's or network's push app, managed from the console.
 */
function sfPushAppControllerSetUp(): array
{
    $fakes  = sfPushAppsSetUp();
    $schema = sfPushAppsDb()->getSchemaBuilder();
    foreach (['stores', 'networks'] as $name) {
        $schema->dropIfExists($name);
        $schema->create($name, function ($table) {
            $table->increments('id');
            foreach (['uuid', 'public_id', 'company_uuid', 'key', 'name', 'options', 'translations'] as $column) {
                $table->text($column)->nullable();
            }
            $table->timestamps();
            $table->softDeletes();
        });
    }
    $db = sfPushAppsDb();
    $db->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'company_uuid' => 'company_uuid', 'key' => 'store_key', 'name' => 'Corner Store']);
    $db->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_public', 'company_uuid' => 'company_uuid', 'key' => 'network_key', 'name' => 'Corner Network']);
    $db->table('stores')->insert(['uuid' => 'other_store_uuid', 'public_id' => 'store_other', 'company_uuid' => 'other_company', 'key' => 'other_key', 'name' => 'Elsewhere']);
    session(['company' => 'company_uuid', 'user' => 'user_uuid']);

    return $fakes;
}

function sfPushAppControllerSave(string $owner, array $input)
{
    return sfPushAppsWithEvents(fn () => (new PushAppController())->save($owner, Request::create('/push-apps/' . $owner, 'PUT', $input)));
}

function sfPushAppControllerError($response): array
{
    return [$response->getStatusCode(), $response->getData(true)['error'] ?? null];
}

function sfPushAppControllerTest(string $owner, array $input)
{
    return (new PushAppController())->sendTest($owner, Request::create('/push-apps/' . $owner . '/test', 'POST', $input));
}

test('push app endpoints use the notification channel permissions they replace', function () {
    $controller = new PushAppController();

    expect($controller->getService())->toBe('storefront')
        ->and($controller->getResourceSingularName())->toBe('notification_channel')
        ->and($controller->getPermissionResourceName())->toBe('notification-channel');
});

test('a store or network shows its push app, without secrets, only within the company', function () {
    sfPushAppControllerSetUp();
    $controller = new PushAppController();

    expect($controller->show('store_uuid')->getData(true))->toBe(['push_app' => null])
        ->and(sfPushAppControllerError($controller->show('store_other')))->toBe([404, 'Store or network not found.']);

    sfPushAppsCreate(['uuid' => 'store_app', 'public_id' => 'store_app_public', 'key' => 'push_app_store', 'meta' => [StorefrontPushApps::CHANNEL_KEYS => ['noty_channel_apn']]], [
        'apn'           => sfPushAppsApn(),
        'fcm'           => sfPushAppsFcm(),
        'apple_sign_in' => ['key_id' => 'SIWAKEY001', 'private_key_content' => 'secret'],
    ]);
    sfPushAppsCreate(['uuid' => 'network_app', 'owner_uuid' => 'network_uuid', 'owner_type' => Network::class]);
    sfPushAppsDb()->table('user_devices')->insert([['uuid' => 'd1', 'token' => 'a', 'push_app_uuid' => 'store_app'], ['uuid' => 'd2', 'token' => 'b', 'push_app_uuid' => 'store_app']]);

    $store   = $controller->show('store_public')->getData(true)['push_app'];
    $network = $controller->show('network_uuid')->getData(true)['push_app'];
    session(['company' => null]);
    $noCompany = $controller->show('store_uuid');

    expect($store)->toMatchArray([
        'id'                     => 'store_app_public',
        'name'                   => 'Corner Store',
        'key'                    => 'push_app_store',
        'status'                 => 'active',
        'devices'                => 2,
        'migrated_from_channels' => true,
        'apple_sign_in'          => ['key_id' => 'SIWAKEY001', 'team_id' => null, 'app_bundle_id' => null, 'has_key' => true],
    ])
        ->and($store['providers']['apn'])->toMatchArray(['key_id' => 'KEY1234567', 'app_bundle_id' => 'com.cornerstore.app', 'has_key' => true])
        ->and($store['providers']['fcm'])->toMatchArray(['project_id' => 'corner-store', 'has_credentials' => true])
        ->and(json_encode($store))->not->toContain('PRIVATE KEY')
        ->and(json_encode($store))->not->toContain('secret')
        ->and($network)->toMatchArray(['apple_sign_in' => null, 'migrated_from_channels' => false, 'devices' => 0])
        ->and(sfPushAppControllerError($noCompany))->toBe([404, 'Store or network not found.']);
});

test('saving creates the storefront\'s push app, then updates it and keeps secrets left empty', function () {
    sfPushAppControllerSetUp();

    $created = sfPushAppControllerSave('store_uuid', ['apn' => sfPushAppsApn(), 'fcm' => sfPushAppsFcm(['android_channel_id' => 'orders'])]);
    $app     = PushApp::where('owner_uuid', 'store_uuid')->first();

    expect($created->getData(true)['push_app'])->toMatchArray(['name' => 'Corner Store', 'status' => 'active'])
        ->and($app->only(['company_uuid', 'created_by_uuid', 'owner_type']))->toBe(['company_uuid' => 'company_uuid', 'created_by_uuid' => 'user_uuid', 'owner_type' => Store::class])
        ->and($app->key)->toStartWith('push_app_')
        ->and($app->provider('fcm')['android_channel_id'])->toBe('orders');

    // Edit: rename, switch off, keep the .p8 and service account, add a Sign in with Apple key, drop Firebase's channel.
    sfPushAppControllerSave('store_public', [
        'name'          => '  Corner Store App ',
        'status'        => 'inactive',
        'apn'           => sfPushAppsApn(['private_key_content' => '', 'environment' => 'sandbox']),
        'fcm'           => ['credentials' => '', 'android_channel_id' => ''],
        'apple_sign_in' => ['key_id' => 'SIWAKEY001', 'private_key_content' => "-----BEGIN PRIVATE KEY-----\nsiwa\n-----END PRIVATE KEY-----"],
    ]);
    $app->refresh();

    expect($app->name)->toBe('Corner Store App')
        ->and($app->status)->toBe('inactive')
        ->and($app->provider('apn'))->toMatchArray(['environment' => 'sandbox', 'private_key_content' => sfPushAppsApn()['private_key_content']])
        ->and($app->provider('fcm'))->toBe(['credentials' => sfPushAppsFcm()['credentials']])
        ->and($app->provider('apple_sign_in')['key_id'])->toBe('SIWAKEY001');

    // A Sign in with Apple key left empty keeps the saved one; clearing a provider switches it off.
    sfPushAppControllerSave('store_uuid', ['apple_sign_in' => ['key_id' => 'SIWAKEY002', 'team_id' => 'TEAM999999', 'app_bundle_id' => 'com.cornerstore.other'], 'apn' => []]);
    $app->refresh();

    expect($app->provider('apple_sign_in'))->toBe(['key_id' => 'SIWAKEY002', 'team_id' => 'TEAM999999', 'app_bundle_id' => 'com.cornerstore.other', 'private_key_content' => "-----BEGIN PRIVATE KEY-----\nsiwa\n-----END PRIVATE KEY-----"])
        ->and($app->provider('apn'))->toBeNull()
        ->and($app->name)->toBe('Corner Store App');

    sfPushAppControllerSave('store_uuid', ['apple_sign_in' => ['key_id' => ' ']]);

    expect($app->refresh()->provider('apple_sign_in'))->toBeNull()
        ->and(PushApp::where('owner_uuid', 'store_uuid')->get())->toHaveCount(1);
});

test('saving says exactly what to fix', function (array $input, string $message) {
    sfPushAppControllerSetUp();
    sfPushAppsCreate(['uuid' => 'store_app'], ['apn' => sfPushAppsApn()]);

    $owner = $input['owner'] ?? 'store_uuid';
    unset($input['owner']);

    expect(sfPushAppControllerError(sfPushAppControllerSave($owner, $input)))->toBe([$owner === 'store_uuid' ? 422 : 404, $message]);
})->with([
    'no name'                 => [['name' => ' '], 'Give the push app a name, for example your app’s name on the App Store.'],
    'bad status'              => [['status' => 'paused'], 'Choose whether to send push notifications through this app.'],
    'apple settings'          => [['apn' => ['key_id' => 'short']], 'The Key ID should be the 10 letters and numbers shown next to the key in your Apple Developer account, like ABC123DEFG.'],
    'firebase app config'     => [['fcm' => ['credentials' => '{"project_info":{},"client":[]}']], 'That looks like google-services.json, the file your Android app is built with. Paste the service account key instead: Firebase console → Project settings → Service accounts → Generate new private key.'],
    'sign in key id missing'  => [['apple_sign_in' => ['team_id' => 'TEAM123456']], 'Enter the Key ID of your Sign in with Apple key, or clear the Sign in with Apple settings to use your Apple push key.'],
    'sign in key id shape'    => [['apple_sign_in' => ['key_id' => 'nope']], 'The Sign in with Apple Key ID should be the 10 letters and numbers shown next to the key in your Apple Developer account, like XYZ987WXYZ.'],
    'sign in team id shape'   => [['apple_sign_in' => ['key_id' => 'SIWAKEY001', 'team_id' => 'team']], 'The Sign in with Apple Team ID should be 10 letters and numbers, like TEAM123456.'],
    'sign in bundle id shape' => [['apple_sign_in' => ['key_id' => 'SIWAKEY001', 'app_bundle_id' => 'nodots']], 'The Sign in with Apple bundle ID should look like com.acme.shop: the Bundle Identifier of your iOS app in Xcode.'],
    'sign in key missing'     => [['apple_sign_in' => ['key_id' => 'SIWAKEY001']], 'Add the .p8 file of your Sign in with Apple key.'],
    'sign in key not a p8'    => [['apple_sign_in' => ['key_id' => 'SIWAKEY001', 'private_key_content' => 'abc']], 'The Sign in with Apple key should be the whole contents of the .p8 file, starting with "-----BEGIN PRIVATE KEY-----".'],
    'unknown storefront'      => [['owner' => 'missing'], 'Store or network not found.'],
]);

test('a Sign in with Apple key needs a team and bundle ID when the app has no Apple push settings', function () {
    expect(PushAppController::appleSignIn(['key_id' => 'SIWAKEY001'], null, null))->toBe('Enter your Apple Team ID for Sign in with Apple. It’s under Membership details in your Apple Developer account.')
        ->and(PushAppController::appleSignIn(['key_id' => 'SIWAKEY001', 'team_id' => 'TEAM123456'], null, null))->toBe('Enter the bundle ID of your iOS app for Sign in with Apple, like com.acme.shop.')
        ->and(PushAppController::appleSignIn(['key_id' => 'SIWAKEY001', 'private_key_content' => '-----BEGIN PRIVATE KEY-----'], null, ['team_id' => 'TEAM123456', 'app_bundle_id' => 'com.acme.shop']))->toBe(['key_id' => 'SIWAKEY001', 'private_key_content' => '-----BEGIN PRIVATE KEY-----'])
        ->and(PushAppController::appleSignIn(['key_id' => null, 'team_id' => ''], ['private_key_content' => 'saved'], null))->toBeNull();
});

test('deleting removes the storefront\'s push app', function () {
    sfPushAppControllerSetUp();
    sfPushAppsCreate(['uuid' => 'store_app']);
    $controller = new PushAppController();

    expect($controller->destroy('store_uuid')->getData(true))->toBe(['status' => 'ok'])
        ->and(PushApp::query()->count())->toBe(0)
        ->and(sfPushAppControllerError($controller->destroy('store_uuid')))->toBe([404, 'Push app not found.'])
        ->and(sfPushAppControllerError($controller->destroy('nowhere')))->toBe([404, 'Push app not found.']);
});

test('a test push goes to one token with the push app\'s settings, and says what\'s missing', function () {
    $fakes = sfPushAppControllerSetUp();

    expect(sfPushAppControllerError(sfPushAppControllerTest('store_uuid', ['token' => 'abc'])))->toBe([404, 'Save the push app before sending a test push.'])
        ->and(sfPushAppControllerError(sfPushAppControllerTest('nowhere', ['token' => 'abc'])))->toBe([404, 'Save the push app before sending a test push.']);

    $app = sfPushAppsCreate(['uuid' => 'store_app', 'public_id' => 'store_app_public'], ['apn' => sfPushAppsApn()]);

    expect(sfPushAppControllerError(sfPushAppControllerTest('store_uuid', ['token' => ' '])))->toBe([422, 'Paste a device token from a phone with the app installed.'])
        ->and(sfPushAppControllerError(sfPushAppControllerTest('store_uuid', ['token' => 'instance:token'])))->toBe([422, 'This is a Firebase token, and the push app has no Firebase service account key yet. Add one first.']);

    $sent = sfPushAppControllerTest('store_uuid', ['token' => 'abc123', 'environment' => 'sandbox', 'title' => 'Hello']);

    expect($sent->getData(true))->toBe(['status' => 'sent', 'reason' => null, 'sent' => true])
        ->and($fakes['apn']->calls)->toBe([['store_app_public', 'sandbox', ['abc123'], 'Hello']]);

    $fakes['apn']->outcomes = ['bad-token' => PushOutcome::error('400 BadTopic')];
    expect(sfPushAppControllerTest('store_uuid', ['token' => 'bad-token', 'platform' => 'ios'])->getData(true))->toBe(['status' => 'error', 'reason' => '400 BadTopic', 'sent' => false]);

    // Without Apple settings an Apple token can't be sent, and a switched-off app sends nothing.
    $app->setProvider('apn', null)->setProvider('fcm', sfPushAppsFcm())->save();
    expect(sfPushAppControllerError(sfPushAppControllerTest('store_uuid', ['token' => 'abc123', 'push_provider' => 'apns'])))->toBe([422, 'This is an Apple device token, and the push app has no Apple settings yet. Add them, or choose “Firebase token” if your iOS app gets its tokens from Firebase.'])
        ->and(sfPushAppControllerTest('store_uuid', ['token' => 'instance:token'])->getData(true)['sent'])->toBeTrue();

    $app->update(['status' => 'inactive']);
    expect(sfPushAppControllerError(sfPushAppControllerTest('store_uuid', ['token' => 'instance:token'])))->toBe([422, 'Sending is turned off for this push app. Turn on “Send push notifications” and save, then try again.']);
});
