import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Route | networks/index/network/settings', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:hostRouter', class extends Service {});
    });

    function stubAbilities(owner, denied = []) {
        owner.unregister('service:abilities');
        owner.register(
            'service:abilities',
            class extends Service {
                can(ability) {
                    return !denied.includes(ability);
                }
            }
        );
    }

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:networks/index/network/settings');
        assert.ok(route);
    });

    test('it loads the gateways and notification channels the network owns', async function (assert) {
        stubAbilities(this.owner);
        const route = this.owner.lookup('route:networks/index/network/settings');
        const store = this.owner.lookup('service:store');
        const requests = [];
        const gateway = { id: 'gateway_uuid' };
        const channel = { id: 'channel_uuid' };
        store.query = (modelName, params) => {
            requests.push({ modelName, params });
            return Promise.resolve({ toArray: () => (modelName === 'gateway' ? [gateway] : [channel]) });
        };
        const network = {
            id: 'network_uuid',
            setProperties(properties) {
                Object.assign(this, properties);
            },
        };

        await route.afterModel(network);

        assert.deepEqual(requests, [
            { modelName: 'gateway', params: { owner_uuid: 'network_uuid', limit: -1 } },
            { modelName: 'notification-channel', params: { owner_uuid: 'network_uuid', limit: -1 } },
        ]);
        assert.deepEqual(network.gateways, [gateway], 'a gateway saved earlier is shown after a refresh');
        assert.deepEqual(network.notification_channels, [channel], 'a channel saved earlier is shown after a refresh');
    });

    test('the loaded gateways and channels fill the network record, which a new one is added to', async function (assert) {
        stubAbilities(this.owner);
        const route = this.owner.lookup('route:networks/index/network/settings');
        const store = this.owner.lookup('service:store');
        const network = store.push({ data: { id: 'network_uuid', type: 'network', attributes: { name: 'Market' } } });
        const [gateway, newGateway] = store.push({
            data: [
                { id: 'gateway_uuid', type: 'gateway', attributes: { name: 'Stripe', owner_uuid: 'network_uuid' } },
                { id: 'new_gateway_uuid', type: 'gateway', attributes: { name: 'Cash', owner_uuid: 'network_uuid' } },
            ],
        });
        const channel = store.push({ data: { id: 'channel_uuid', type: 'notification-channel', attributes: { name: 'FCM', owner_uuid: 'network_uuid' } } });
        store.query = (modelName) => Promise.resolve(modelName === 'gateway' ? [gateway] : [channel]);

        await route.afterModel(network);

        assert.deepEqual(
            network.gateways.map((record) => record.id),
            ['gateway_uuid']
        );
        assert.deepEqual(
            network.notification_channels.map((record) => record.id),
            ['channel_uuid']
        );

        network.gateways.pushObject(newGateway);
        assert.deepEqual(
            network.gateways.map((record) => record.id),
            ['gateway_uuid', 'new_gateway_uuid'],
            'the settings page adds a created gateway to the same list'
        );
    });

    test('it skips what the user may not list', async function (assert) {
        stubAbilities(this.owner, ['storefront list gateway', 'storefront list notification-channel']);
        const route = this.owner.lookup('route:networks/index/network/settings');
        const store = this.owner.lookup('service:store');
        store.query = () => assert.ok(false, 'nothing is requested');
        const network = {
            id: 'network_uuid',
            setProperties(properties) {
                Object.assign(this, properties);
            },
        };

        await route.afterModel(network);

        assert.deepEqual(network.gateways, []);
        assert.deepEqual(network.notification_channels, []);
    });
});
