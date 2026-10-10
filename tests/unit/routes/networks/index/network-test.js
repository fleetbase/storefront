import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class StorefrontStub extends Service {
    calls = [];

    setActiveNetwork(network) {
        this.calls.push(['set', network?.id]);
    }

    clearActiveNetwork() {
        this.calls.push(['clear']);
    }
}

module('Unit | Route | networks/index/network', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:storefront', StorefrontStub);
        this.owner.register('service:hostRouter', class extends Service {});
    });

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:networks/index/network');
        assert.ok(route);
    });

    test('entering a network sets the network context and leaving clears it', function (assert) {
        const route = this.owner.lookup('route:networks/index/network');
        const storefront = this.owner.lookup('service:storefront');

        route.afterModel({ id: 'network_uuid' });
        route.deactivate();

        assert.deepEqual(storefront.calls, [['set', 'network_uuid'], ['clear']]);
    });
});
