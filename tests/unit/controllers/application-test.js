import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

class FetchStub {
    static create(props = {}) {
        return Object.assign(new this(), props);
    }

    requests = [];
    response = {
        results: [
            {
                label: 'Pepperoni Pizza',
                description: 'SKU-1 published product_123',
                icon: 'box',
                type: 'Product',
                route: 'console.storefront.products.index.index.edit',
                breadcrumb: 'Storefront > Products',
                models: ['product_123'],
            },
        ],
    };

    get(url, params, options) {
        this.requests.push({ url, params, options });
        return Promise.resolve(this.response);
    }
}

class StorefrontStub {
    static create(props = {}) {
        return Object.assign(new this(), props);
    }

    activeStore = {
        id: 'store_uuid',
        public_id: 'store_123',
        currency: 'USD',
    };
    activeNetwork = null;
    createNetworkCalls = [];

    get isNetworkContext() {
        return Boolean(this.activeNetwork);
    }

    setActiveStorefront(store) {
        this.activeStore = store;
    }

    createNewNetwork(options) {
        this.createNetworkCalls.push(options);
    }
}

class AbilitiesStub {
    static create(props = {}) {
        return Object.assign(new this(), props);
    }

    can() {
        return true;
    }
}

class StoreStub {
    static create(props = {}) {
        return Object.assign(new this(), props);
    }

    requests = [];
    categoriesByStore = {
        store_uuid: [
            {
                id: 'category_uuid',
                name: 'Pizza',
                description: 'Hot pizza menu.',
                slug: 'pizza',
            },
            {
                id: 'category_no_slug',
                name: 'No Slug',
                description: 'Ignored without a route model.',
            },
        ],
        next_store_uuid: [
            {
                id: 'category_burger_uuid',
                name: 'Burgers',
                description: 'Burger menu.',
                slug: 'burgers',
            },
        ],
    };
    deferred = {};

    query(modelName, params) {
        this.requests.push({ modelName, params });

        if (this.deferred[params.owner_uuid]) {
            return this.deferred[params.owner_uuid].promise;
        }

        return Promise.resolve({
            toArray: () => this.categoriesByStore[params.owner_uuid] ?? [],
        });
    }

    deferStore(ownerUuid) {
        let resolve;
        const promise = new Promise((promiseResolve) => {
            resolve = promiseResolve;
        });

        this.deferred[ownerUuid] = { promise, resolve };
        return this.deferred[ownerUuid];
    }
}

class LoaderStub {
    static create(props = {}) {
        return Object.assign(new this(), props);
    }

    show() {
        return {};
    }

    removeLoader() {}
}

class HostRouterStub {
    static create(props = {}) {
        return Object.assign(new this(), props);
    }

    refreshCount = 0;
    transitions = [];

    refresh() {
        this.refreshCount++;
        return Promise.resolve();
    }

    transitionTo(...args) {
        this.transitions.push(args);
        return Promise.resolve();
    }

    isActive() {
        return false;
    }
}

module('Unit | Controller | application', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStub);
        this.owner.register('service:storefront', StorefrontStub);
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register('service:store', StoreStub);
        this.owner.register('service:loader', LoaderStub);
        this.owner.register('service:host-router', HostRouterStub);
    });

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:application');
        assert.ok(controller);
    });

    test('it builds Storefront sidebar navigator items', async function (assert) {
        const controller = this.owner.lookup('controller:application');
        const items = controller.navigationItems;

        assert.deepEqual(
            items.map((item) => item.label),
            ['Dashboard', 'Orders', 'Products', 'Catalogs', 'Customers', 'Trucks', 'Promotions', 'Settings', 'Launch App'],
            'root items match the Storefront sidebar sections'
        );
        assert.deepEqual(
            items.map((item) => item.icon),
            ['home', 'file-invoice-dollar', 'box', 'book-open', 'users', 'truck', 'bullhorn', 'cogs', 'rocket'],
            'root items keep Storefront-specific icons'
        );
        assert.strictEqual(items[0].route, 'console.storefront.home');
        assert.strictEqual(items[2].permission, 'storefront list product');
        assert.true(items[2].visible, 'product visibility uses the abilities service');
        assert.false(items[2].disabled, 'resource items are enabled when an active store exists');
        assert.strictEqual(items[2].route, 'console.storefront.products', 'products is one entry; its page carries the categories');
        assert.strictEqual(items[2].children, undefined, 'no category submenu');
        assert.strictEqual(items[7].route, 'console.storefront.settings', 'settings is one entry; its page carries the section rail');
        assert.strictEqual(items[8].url, 'https://github.com/fleetbase/storefront-app', 'launch app remains an external URL item');
    });

    test('it disables Storefront resource sidebar items without an active store', function (assert) {
        const storefront = this.owner.lookup('service:storefront');
        storefront.activeStore = null;

        const controller = this.owner.lookup('controller:application');
        const items = controller.navigationItems;

        assert.false(items[0].disabled, 'dashboard remains available');
        assert.true(items[2].disabled, 'products are disabled without an active store');
        assert.true(items[7].disabled, 'settings are disabled without an active store');
    });

    test('it fetches Storefront resource search results for the sidebar navigator', async function (assert) {
        const controller = this.owner.lookup('controller:application');
        const fetch = this.owner.lookup('service:fetch');
        const results = await controller.searchNavigation({ query: ' pizza ', limit: 12 });

        assert.deepEqual(
            fetch.requests,
            [
                {
                    url: 'search',
                    params: {
                        query: 'pizza',
                        limit: 12,
                        storefront: 'store_123',
                    },
                    options: { namespace: 'storefront/int/v1' },
                },
            ],
            'calls the Storefront search endpoint with the active store'
        );
        assert.deepEqual(results, fetch.response.results, 'returns navigator-ready endpoint results');
    });

    test('it skips blank Storefront search queries and missing active stores', async function (assert) {
        const controller = this.owner.lookup('controller:application');
        const fetch = this.owner.lookup('service:fetch');
        let results = await controller.searchNavigation({ query: '   ', limit: 12 });

        assert.deepEqual(results, []);
        assert.deepEqual(fetch.requests, [], 'blank queries do not call the adapter');

        const storefront = this.owner.lookup('service:storefront');
        storefront.activeStore = null;
        results = await controller.searchNavigation({ query: 'pizza', limit: 12 });

        assert.deepEqual(results, []);
        assert.deepEqual(fetch.requests, [], 'missing active stores do not call the adapter');
    });

    test('it returns empty Storefront search results when the adapter fails', async function (assert) {
        const controller = this.owner.lookup('controller:application');
        const fetch = this.owner.lookup('service:fetch');

        fetch.get = () => Promise.reject(new Error('adapter failed'));

        const results = await controller.searchNavigation({ query: 'pizza', limit: 12 });

        assert.deepEqual(results, []);
    });

    test('it rewrites the sidebar for the network context', function (assert) {
        const storefront = this.owner.lookup('service:storefront');
        const hostRouter = this.owner.lookup('service:host-router');
        storefront.activeNetwork = { id: 'network_uuid', public_id: 'network_123', name: 'Downtown Market' };

        const controller = this.owner.lookup('controller:application');
        const items = controller.navigationItems;

        assert.true(controller.isNetworkContext);
        assert.deepEqual(
            items.map((item) => item.label),
            ['Overview', 'Stores', 'Trucks', 'Orders', 'Customers', 'Categories', 'Promotions', 'Settings'],
            'network sections replace the store sections'
        );
        assert.deepEqual(
            items.map((item) => item.id),
            [
                'network:network_123:index',
                'network:network_123:stores',
                'network:network_123:trucks',
                'network:network_123:orders',
                'network:network_123:customers',
                'network:network_123:categories',
                'network:network_123:promotions',
                'network:network_123:settings',
            ],
            'items are keyed by network so two networks never share an entry'
        );
        assert.ok(
            items.every((item) => item.route === undefined),
            'network items do not use shared route-name matching'
        );

        hostRouter.isActive = (route, publicId) => route === 'console.storefront.networks.index.network.trucks' && publicId === 'network_123';
        assert.true(items[2].activeWhen(), 'the trucks section is active on the network trucks route');
        assert.false(items[1].activeWhen(), 'the stores section is not');

        hostRouter.isActive = (route, publicId) => route === 'console.storefront.networks.index.network.store' && publicId === 'network_123';
        assert.true(items[1].activeWhen(), "a member store's details page keeps the stores section active");
        assert.false('alsoActiveOn' in items[1], 'the extra routes stay out of the sidebar item');

        items[3].onClick();
        assert.deepEqual(hostRouter.transitions, [['console.storefront.networks.index.network.orders', 'network_123']], 'sections transition with the network id');
    });

    test('switching to a network enters its overview', function (assert) {
        const controller = this.owner.lookup('controller:application');
        const hostRouter = this.owner.lookup('service:host-router');

        controller.switchActiveNetwork({ id: 'network_uuid', public_id: 'network_123' });

        assert.deepEqual(hostRouter.transitions, [['console.storefront.networks.index.network.index', 'network_123']]);
    });

    test('switching to a store from a network lands on the store dashboard', async function (assert) {
        const storefront = this.owner.lookup('service:storefront');
        const hostRouter = this.owner.lookup('service:host-router');
        storefront.activeNetwork = { id: 'network_uuid', public_id: 'network_123', name: 'Downtown Market' };

        const controller = this.owner.lookup('controller:application');
        await controller.switchActiveStore({ id: 'next_store_uuid', public_id: 'store_456', name: 'Next Store' });

        assert.deepEqual(hostRouter.transitions, [['console.storefront.home']], 'leaves the network page instead of refreshing it');
        assert.strictEqual(hostRouter.refreshCount, 0);
        assert.strictEqual(storefront.activeStore.id, 'next_store_uuid', 'the selected store becomes active');
    });

    test('creating a network switches into it once it is saved', function (assert) {
        const controller = this.owner.lookup('controller:application');
        const storefront = this.owner.lookup('service:storefront');
        const hostRouter = this.owner.lookup('service:host-router');

        controller.createNewNetwork();
        assert.strictEqual(storefront.createNetworkCalls.length, 1, 'delegates to the storefront service');

        storefront.createNetworkCalls[0].onSuccess({ id: 'network_uuid', public_id: 'network_123' });
        assert.deepEqual(hostRouter.transitions, [['console.storefront.networks.index.network.index', 'network_123']]);
    });
});
