import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click, fillIn } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class StoreRecord {
    static modelName = 'store';
    id = 'store_1';
    name = 'Corner Store';
}

const pushApp = (overrides = {}) => ({
    id: 'push_app_public',
    name: 'Corner Store',
    key: 'push_app_abc',
    status: 'active',
    providers: { apn: { app_bundle_id: 'com.corner.shop', key_id: 'ABC123DEFG', team_id: 'TEAM123456', environment: null, has_key: true } },
    apple_sign_in: null,
    devices: 2,
    migrated_from_channels: true,
    ...overrides,
});

class FetchStubService extends Service {
    requests = [];
    current = null;
    error = null;
    get(url, data, options) {
        this.requests.push(['GET', url, options?.namespace]);
        return Promise.resolve({ push_app: this.current });
    }
    put(url, body) {
        this.requests.push(['PUT', url, body]);
        if (this.error) {
            return Promise.reject(new Error(this.error));
        }
        this.current = pushApp({ name: body.name });
        return Promise.resolve({ push_app: this.current });
    }
    post(url, body) {
        this.requests.push(['POST', url, body]);
        return Promise.resolve({ sent: false, status: 'dead', reason: 'Unregistered' });
    }
    delete(url) {
        this.requests.push(['DELETE', url]);
        this.current = null;
        return Promise.resolve({ status: 'ok' });
    }
}

class ModalsManagerStubService extends Service {
    confirm(options) {
        return options.confirm({ startLoading() {}, stopLoading() {}, done() {} });
    }
}

class AbilitiesStubService extends Service {
    can() {
        return true;
    }
    cannot() {
        return false;
    }
}

class NotificationsStubService extends Service {
    messages = [];
    success(message) {
        this.messages.push(['success', message]);
    }
    serverError(error) {
        this.messages.push(['error', error?.message]);
    }
}

module('Integration | Component | storefront/push-app-settings', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:modals-manager', ModalsManagerStubService);
        this.owner.register('service:abilities', AbilitiesStubService);
        this.owner.register('service:notifications', NotificationsStubService);
        this.set('store', new StoreRecord());
    });

    test('a store without a push app opens an empty form named after the store', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<Storefront::PushAppSettings @owner={{this.store}} />`);

        assert.deepEqual(fetch.requests[0], ['GET', 'push-apps/store_1', 'storefront/int/v1']);
        assert.dom('[data-test-push-app-add]').exists();

        await click('[data-test-push-app-add]');
        assert.dom('[data-test-push-app-name]').hasValue('Corner Store', 'the name starts as the store name');
        assert.dom('[data-test-push-app-no-package-name]').exists('it says Android needs no package name');
    });

    test('saving shows the server error, then the saved push app', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<Storefront::PushAppSettings @owner={{this.store}} />`);
        await click('[data-test-push-app-add]');

        fetch.error = 'Enter the bundle ID of your iOS app, like com.acme.driver.';
        await click('[data-test-push-app-save]');
        assert.dom('[data-test-push-app-error]').includesText('Enter the bundle ID of your iOS app');

        fetch.error = null;
        await fillIn('[data-test-push-app-bundle-id]', 'com.corner.shop');
        await click('[data-test-push-app-save]');

        const [, url, body] = fetch.requests.at(-1);
        assert.strictEqual(url, 'push-apps/store_1');
        assert.strictEqual(body.apn.app_bundle_id, 'com.corner.shop');
        assert.deepEqual(Object.keys(body).sort(), ['apn', 'apple_sign_in', 'fcm', 'name', 'status']);
        assert.dom('[data-test-push-app-key]').includesText('push_app_abc');
        assert.dom('[data-test-push-app-migrated]').exists();
    });

    test('an existing push app is edited without its secrets, tested and removed', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        fetch.current = pushApp();

        await render(hbs`<Storefront::PushAppSettings @owner={{this.store}} />`);
        assert.dom('[data-test-push-app="push_app_public"]').exists();

        await click('[data-test-push-app-edit]');
        assert.dom('[data-test-push-app-bundle-id]').hasValue('com.corner.shop');
        await click('[data-test-push-app-save]');
        const body = fetch.requests.at(-1)[2];
        assert.strictEqual(body.apn.private_key_content, '', 'the saved key is never sent back');

        await click('[data-test-push-app-test-toggle]');
        await fillIn('[data-test-push-app-test-token]', 'abc123');
        await click('[data-test-push-app-send-test]');
        assert.deepEqual(fetch.requests.at(-1).slice(0, 2), ['POST', 'push-apps/store_1/test']);
        assert.dom('[data-test-push-app-test-result]').includesText('Unregistered');

        await click('[data-test-push-app-delete]');
        assert.deepEqual(fetch.requests.at(-1), ['DELETE', 'push-apps/store_1']);
        assert.dom('[data-test-push-app-add]').exists();
    });
});
