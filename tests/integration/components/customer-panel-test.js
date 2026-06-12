import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class UniverseStubService extends Service {
    _createMenuItem(title, route = null, options = {}) {
        return {
            id: title.toLowerCase(),
            slug: title.toLowerCase(),
            title,
            label: title,
            route,
            icon: options.icon,
            component: options.component,
            componentParams: options.componentParams ?? {},
        };
    }

    getMenuItemsFromRegistry() {
        return [];
    }
}

class ContextPanelStubService extends Service {
    focus() {}

    clear() {}
}

class StorefrontStubService extends Service {
    activeStore = { public_id: 'store_1' };
}

class FetchStubService extends Service {
    get() {
        return [];
    }
}

class IntlStubService extends Service {
    t(key) {
        const translations = {
            'storefront.customers.customer-panel.details.web-url': 'Web URL',
            'storefront.common.title': 'Title',
            'storefront.common.internal-id': 'Internal ID',
            'storefront.common.email': 'Email',
            'storefront.common.phone': 'Phone',
            'storefront.common.type': 'Type',
            'storefront.common.orders': 'Orders',
        };

        return translations[key] ?? key;
    }
}

class StorefrontOrderActionsStubService extends Service {
    actionItemsFor() {
        return [];
    }

    viewOrder() {}
}

module('Integration | Component | customer-panel', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:universe', UniverseStubService);
        this.owner.register('service:context-panel', ContextPanelStubService);
        this.owner.register('service:storefront', StorefrontStubService);
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:intl', IntlStubService);
        this.owner.register('service:storefront-order-actions', StorefrontOrderActionsStubService);
    });

    test('it renders the shared resource panel with customer tabs', async function (assert) {
        this.set('customer', {
            id: 'customer_uuid_1',
            public_id: 'contact_1',
            internal_id: 'internal_1',
            name: 'Ava Chen',
            title: 'Operations Lead',
            email: 'ava.chen@example.test',
            phone: '+15550201',
            type: 'customer',
        });

        await render(hbs`<CustomerPanel @customer={{this.customer}} />`);

        assert.dom('.resource-panel-header').includesText('Ava Chen');
        assert.dom('[role="tab"]').exists({ count: 2 });
        assert.dom('[role="tab"]').includesText('Details');
        assert.dom('[role="tab"]').includesText('Orders');
        assert.dom('[role="tabpanel"]').includesText('ava.chen@example.test');

        await click('[data-tab-id="orders"]');

        assert.dom('[role="tabpanel"]').includesText('Orders');
    });
});
