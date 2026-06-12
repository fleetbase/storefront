import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

class FetchStub {
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
    activeStore = {
        id: 'store_uuid',
        public_id: 'store_123',
        currency: 'USD',
    };

    setActiveStorefront(store) {
        this.activeStore = store;
    }
}

class AbilitiesStub {
    can() {
        return true;
    }
}

class StoreStub {
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
    show() {
        return {};
    }

    removeLoader() {}
}

class HostRouterStub {
    refreshCount = 0;

    refresh() {
        this.refreshCount++;
        return Promise.resolve();
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
        await controller.loadProductCategories();
        const items = controller.navigationItems;

        assert.deepEqual(
            items.map((item) => item.label),
            ['Dashboard', 'Products', 'Catalogs', 'Customers', 'Orders', 'Networks', 'Food Trucks', 'Promotions', 'Settings', 'Launch App'],
            'root items match the Storefront sidebar sections'
        );
        assert.deepEqual(
            items.map((item) => item.icon),
            ['home', 'box', 'book-open', 'users', 'file-invoice-dollar', 'network-wired', 'truck', 'bullhorn', 'cogs', 'rocket'],
            'root items keep Storefront-specific icons'
        );
        assert.strictEqual(items[0].route, 'console.storefront.home');
        assert.strictEqual(items[1].permission, 'storefront list product');
        assert.true(items[1].visible, 'product visibility uses the abilities service');
        assert.false(items[1].disabled, 'resource items are enabled when an active store exists');
        assert.deepEqual(
            items[1].children.map((item) => item.label),
            ['All Products', 'Pizza'],
            'products includes all products plus active store product categories'
        );
        assert.deepEqual(
            items[1].children.map((item) => item.route),
            ['console.storefront.products', 'console.storefront.products.index.category'],
            'product categories link to the category route'
        );
        assert.deepEqual(
            items[1].children.map((item) => item.models ?? null),
            [null, ['pizza']],
            'product category route models use category slugs'
        );
        assert.deepEqual(
            items[8].children.map((item) => item.route),
            [
                'console.storefront.settings.index',
                'console.storefront.settings.locations',
                'console.storefront.settings.gateways',
                'console.storefront.settings.api',
                'console.storefront.settings.notifications',
            ],
            'settings children use the requested order'
        );
        assert.strictEqual(items[9].url, 'https://github.com/fleetbase/storefront-app', 'launch app remains an external URL item');
    });

    test('it loads active store product categories for the sidebar navigator', async function (assert) {
        const controller = this.owner.lookup('controller:application');
        const store = this.owner.lookup('service:store');

        await controller.loadProductCategories();

        assert.deepEqual(
            store.requests[0],
            {
                modelName: 'category',
                params: {
                    for: 'storefront_product',
                    owner_uuid: 'store_uuid',
                    limit: -1,
                },
            },
            'loads active store product categories'
        );
        assert.deepEqual(
            controller.productCategoryItems.map((item) => item.label),
            ['Pizza'],
            'category items are navigator-ready'
        );
    });

    test('it reloads product categories after switching active stores', async function (assert) {
        const controller = this.owner.lookup('controller:application');
        const store = this.owner.lookup('service:store');

        await controller.loadProductCategories();
        await controller.switchActiveStore({ id: 'next_store_uuid', public_id: 'store_456', name: 'Next Store' });

        assert.strictEqual(store.requests.length, 2, 'reloads categories after active store switch');
        assert.strictEqual(store.requests[1].params.owner_uuid, 'next_store_uuid', 'uses the new active store id');
        assert.deepEqual(
            controller.productCategoryItems.map((item) => item.label),
            ['Burgers'],
            'navigation category items update to the selected store'
        );
    });

    test('it clears stale categories immediately when switching stores', async function (assert) {
        const controller = this.owner.lookup('controller:application');

        await controller.loadProductCategories();

        assert.deepEqual(
            controller.productCategoryItems.map((item) => item.label),
            ['Pizza'],
            'starts with the first store categories'
        );

        const switchPromise = controller.switchActiveStore({ id: 'next_store_uuid', public_id: 'store_456', name: 'Next Store' });

        assert.deepEqual(controller.productCategoryItems, [], 'old category items are cleared during the switch');

        await switchPromise;
    });

    test('it ignores stale category responses after a store switch', async function (assert) {
        const controller = this.owner.lookup('controller:application');
        const store = this.owner.lookup('service:store');
        const firstStoreDeferred = store.deferStore('store_uuid');

        const firstLoadPromise = controller.loadProductCategories('store_uuid');
        const secondLoadPromise = controller.loadProductCategories('next_store_uuid');

        firstStoreDeferred.resolve({
            toArray: () => store.categoriesByStore.store_uuid,
        });

        await Promise.all([firstLoadPromise, secondLoadPromise]);

        assert.deepEqual(
            controller.productCategoryItems.map((item) => item.label),
            ['Burgers'],
            'newer store categories win over stale responses'
        );
    });

    test('it clears categories and skips query without an active store', async function (assert) {
        const controller = this.owner.lookup('controller:application');
        const store = this.owner.lookup('service:store');
        const storefront = this.owner.lookup('service:storefront');

        await controller.loadProductCategories();
        const requestCount = store.requests.length;

        storefront.activeStore = null;
        const categories = await controller.loadProductCategories();

        assert.deepEqual(categories, [], 'returns an empty category list');
        assert.deepEqual(controller.productCategoryItems, [], 'clears category navigation items');
        assert.strictEqual(store.requests.length, requestCount, 'does not query without a store id');
    });

    test('it disables Storefront resource sidebar items without an active store', function (assert) {
        const storefront = this.owner.lookup('service:storefront');
        storefront.activeStore = null;

        const controller = this.owner.lookup('controller:application');
        const items = controller.navigationItems;

        assert.false(items[0].disabled, 'dashboard remains available');
        assert.true(items[1].disabled, 'products are disabled without an active store');
        assert.true(items[8].disabled, 'settings are disabled without an active store');
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
});
