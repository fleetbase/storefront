import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class StoreRecord {
    static modelName = 'store';
    id = 'store_1';
    name = 'Corner Store';
}

class NetworkRecord {
    static modelName = 'network';
    id = 'network_1';
    name = 'Night Market';
}

const status = (overrides = {}) => ({
    enabled: false,
    phone: '+12025550143',
    phone_display: '+1 202 555 0143',
    has_code: false,
    code_generated_at: null,
    enabled_at: null,
    ...overrides,
});

class FetchStubService extends Service {
    requests = [];
    current = status();
    get(url) {
        this.requests.push(['GET', url]);
        return Promise.resolve({ app_review: this.current });
    }
    put(url, body) {
        this.requests.push(['PUT', url, body]);
        if (body.enabled === true) {
            this.current = status({ enabled: true, has_code: true, code_generated_at: '2026-10-24T09:00:00+00:00' });
            return Promise.resolve({ app_review: { ...this.current, code: '482913' } });
        }
        if (body.regenerate_code) {
            return Promise.resolve({ app_review: { ...this.current, code: '135790' } });
        }
        this.current = { ...this.current, enabled: false };
        return Promise.resolve({ app_review: this.current });
    }
}

class ModalsManagerStubService extends Service {
    confirmed = [];
    confirm(options) {
        this.confirmed.push(options.title);
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

module('Integration | Component | storefront/app-review-access', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:modals-manager', ModalsManagerStubService);
        this.owner.register('service:abilities', AbilitiesStubService);
        this.owner.register('service:notifications', NotificationsStubService);
    });

    test('it turns access on and shows the code once, regenerates it and turns it off', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        this.set('store', new StoreRecord());

        await render(hbs`<Storefront::AppReviewAccess @owner={{this.store}} />`);

        assert.deepEqual(fetch.requests[0], ['GET', 'stores/store_1/app-review']);
        assert.dom('[data-test-app-review-status]').hasText('Off');
        assert.dom('[data-test-app-review-phone]').includesText('+1 202 555 0143');
        assert.dom('[data-test-app-review-code]').doesNotExist();
        assert.dom('[data-test-app-review-access]').includesText('No code yet');

        await click('[data-test-app-review-turn-on]');

        assert.deepEqual(fetch.requests[1], ['PUT', 'stores/store_1/app-review', { enabled: true }]);
        assert.dom('[data-test-app-review-status]').hasText('On');
        assert.dom('[data-test-app-review-code]').includesText('482913');
        assert.dom('[data-test-app-review-access]').includesText('only shown once');

        await click('[data-test-app-review-regenerate]');
        assert.deepEqual(fetch.requests[2], ['PUT', 'stores/store_1/app-review', { regenerate_code: true }]);
        assert.dom('[data-test-app-review-code]').includesText('135790');

        await click('[data-test-app-review-turn-off]');
        assert.deepEqual(fetch.requests[3], ['PUT', 'stores/store_1/app-review', { enabled: false }]);
        assert.dom('[data-test-app-review-status]').hasText('Off');
        assert.dom('[data-test-app-review-code]').doesNotExist('the code is forgotten once access is off');
        assert.dom('[data-test-app-review-turn-on]').exists();
        assert.strictEqual(this.owner.lookup('service:modals-manager').confirmed.length, 2, 'regenerating and turning off are confirmed first');
    });

    test('a code made earlier is never shown again, and a network uses its own endpoint', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        fetch.current = status({ enabled: true, has_code: true, code_generated_at: '2026-10-20T09:00:00+00:00' });
        this.set('network', new NetworkRecord());

        await render(hbs`<Storefront::AppReviewAccess @owner={{this.network}} />`);

        assert.deepEqual(fetch.requests[0], ['GET', 'networks/network_1/app-review']);
        assert.dom('[data-test-app-review-status]').hasText('On');
        assert.dom('[data-test-app-review-code]').doesNotExist();
        assert.dom('[data-test-app-review-access]').includesText('Code created');
        assert.dom('[data-test-app-review-regenerate]').exists();
    });
});
