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

    peekRecord(modelName, id) {
        if (modelName === 'store') {
            return this.stores.find((store) => store.id === id);
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
});
