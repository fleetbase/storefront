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

    test('it seeds tracked active store id from the first available store', function (assert) {
        const service = this.owner.lookup('service:storefront');
        const currentUser = this.owner.lookup('service:current-user');
        const activeStore = service.activeStore;

        assert.strictEqual(activeStore.id, 'store_uuid', 'falls back to the first store');
        assert.strictEqual(currentUser.getOption('activeStorefront'), 'store_uuid', 'persists the fallback store id');
        assert.strictEqual(service.activeStoreId, 'store_uuid', 'tracks the fallback store id');
    });
});
