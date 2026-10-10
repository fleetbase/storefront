import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

class CurrentUserStub {
    options = {};

    getOption(key) {
        return this.options[key];
    }

    setOption(key, value) {
        this.options[key] = value;
    }
}

class StoreStub {
    stores = [
        { id: 'store_uuid', name: 'Fleetbase Market' },
        { id: 'next_store_uuid', name: 'Next Store' },
    ];

    peekAll(modelName) {
        if (modelName === 'store') {
            return {
                firstObject: this.stores[0],
            };
        }

        return {
            firstObject: undefined,
        };
    }

    networks = [{ id: 'network_uuid', name: 'Downtown Market', public_id: 'network_123' }];

    peekRecord(modelName, id) {
        if (modelName === 'store') {
            return this.stores.find((store) => store.id === id);
        }

        if (modelName === 'network') {
            return this.networks.find((network) => network.id === id);
        }
    }
}

module('Unit | Service | storefront', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:current-user', CurrentUserStub);
        this.owner.register('service:store', StoreStub);
    });

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:storefront');
        assert.ok(service);
    });

    test('it tracks active store changes reactively', function (assert) {
        const service = this.owner.lookup('service:storefront');
        const currentUser = this.owner.lookup('service:current-user');

        service.setActiveStorefront({ id: 'next_store_uuid', name: 'Next Store' });

        assert.strictEqual(currentUser.getOption('activeStorefront'), 'next_store_uuid', 'persists the active store id');
        assert.strictEqual(service.activeStoreId, 'next_store_uuid', 'tracks the active store id');
        assert.strictEqual(service.activeStore.name, 'Next Store', 'resolves active store from the tracked id');
    });

    test('active store lookup is read-only until stores are synchronized', function (assert) {
        const service = this.owner.lookup('service:storefront');
        const currentUser = this.owner.lookup('service:current-user');

        assert.strictEqual(service.activeStore, null, 'does not select a store while a getter is being consumed');
        assert.strictEqual(service.findActiveStore(), null, 'legacy lookup remains read-only');
        assert.strictEqual(currentUser.getOption('activeStorefront'), undefined, 'does not persist from a getter');
        assert.strictEqual(service.activeStoreId, undefined, 'does not mutate tracked state from a getter');
    });

    test('it synchronizes tracked active store id from the first available store', function (assert) {
        const service = this.owner.lookup('service:storefront');
        const currentUser = this.owner.lookup('service:current-user');
        const activeStore = service.synchronizeActiveStore();

        assert.strictEqual(activeStore.id, 'store_uuid', 'falls back to the first store');
        assert.strictEqual(currentUser.getOption('activeStorefront'), 'store_uuid', 'persists the fallback store id');
        assert.strictEqual(service.activeStoreId, 'store_uuid', 'tracks the fallback store id');
    });

    test('it replaces stale selections and clears state when no stores exist', function (assert) {
        const service = this.owner.lookup('service:storefront');
        const currentUser = this.owner.lookup('service:current-user');
        const store = this.owner.lookup('service:store');

        currentUser.setOption('activeStorefront', 'missing_store_uuid');
        assert.strictEqual(service.synchronizeActiveStore().id, 'store_uuid', 'replaces a stale selection with the first loaded store');

        store.stores = [];
        assert.strictEqual(service.synchronizeActiveStore([]), null, 'supports a new user with no storefront');
        assert.strictEqual(currentUser.getOption('activeStorefront'), undefined, 'clears the stale persisted selection');
        assert.strictEqual(service.activeStoreId, undefined, 'clears tracked selection outside render');
        assert.strictEqual(service.activeStore, null, 'empty state remains safe to consume from widgets');
    });

    test('it is in the store context until a network is entered', function (assert) {
        const service = this.owner.lookup('service:storefront');
        service.synchronizeActiveStore();

        assert.strictEqual(service.activeNetwork, null, 'no network is active by default');
        assert.false(service.isNetworkContext, 'defaults to the store context');
        assert.strictEqual(service.activeContextType, 'store');
        assert.strictEqual(service.activeContext.id, 'store_uuid', 'the active context is the active store');
        assert.strictEqual(service.getActiveNetwork('name'), null, 'network lookups are empty outside a network');
    });

    test('entering a network scopes the console to it without touching the active store', function (assert) {
        const service = this.owner.lookup('service:storefront');
        const currentUser = this.owner.lookup('service:current-user');
        const events = [];
        service.synchronizeActiveStore();
        service.on('storefront.context.changed', (type, context) => events.push([type, context?.id]));

        service.setActiveNetwork({ id: 'network_uuid' });

        assert.true(service.isNetworkContext, 'the network context is active');
        assert.strictEqual(service.activeContextType, 'network');
        assert.strictEqual(service.activeNetwork.name, 'Downtown Market', 'resolves the network from the store');
        assert.strictEqual(service.activeContext.id, 'network_uuid', 'the active context is the network');
        assert.strictEqual(service.getActiveNetwork('public_id'), 'network_123', 'reads properties of the active network');
        assert.strictEqual(service.activeStore.id, 'store_uuid', 'the active store is kept for when the user leaves the network');
        assert.strictEqual(currentUser.getOption('activeStorefront'), 'store_uuid', 'the network context is not persisted as the storefront');

        service.setActiveNetwork({ id: 'network_uuid' });
        assert.deepEqual(events, [['network', 'network_uuid']], 'setting the same network again does not re-announce the context');

        service.clearActiveNetwork();
        assert.false(service.isNetworkContext, 'leaving the network returns to the store context');
        assert.deepEqual(events, [
            ['network', 'network_uuid'],
            ['store', 'store_uuid'],
        ]);
    });

    test('a network that is not loaded leaves the console in the store context', function (assert) {
        const service = this.owner.lookup('service:storefront');
        service.setActiveNetwork({ id: 'missing_network_uuid' });

        assert.strictEqual(service.activeNetwork, null, 'an unknown network does not resolve');
        assert.false(service.isNetworkContext, 'the store context stays in charge');
    });
});
