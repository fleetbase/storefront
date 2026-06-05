import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class StorefrontStubService extends Service {
    activeStore = { public_id: 'store_1' };
    on() {}
}

class FetchStubService extends Service {
    get() {
        return {
            customers: [
                {
                    public_id: 'contact_1',
                    name: 'Ava Chen',
                    phone: '+15550201',
                    email: 'ava.chen@example.test',
                    orders: 2,
                },
            ],
        };
    }
}

class IntlStubService extends Service {
    t(key) {
        if (key === 'storefront.component.widget.customers.widget-title') {
            return 'Recent Customers';
        }

        return key;
    }
}

class ContextPanelStubService extends Service {
    focus() {}
}

module('Integration | Component | widget/customers', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:storefront', StorefrontStubService);
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:intl', IntlStubService);
        this.owner.register('service:context-panel', ContextPanelStubService);
    });

    test('it renders the polished customers table shell', async function (assert) {
        await render(hbs`<Widget::Customers />`);

        assert.dom('.storefront-customers-widget').exists();
        assert.dom('.storefront-customers-header').includesText('Recent Customers');
        assert.dom('.storefront-widget-count').hasText('1');
        assert.dom('.storefront-customers-table').exists();
        assert.dom('.storefront-customer-id').hasText('contact_1');
        assert.dom('.storefront-customers-table').includesText('Ava Chen');
        assert.dom('.storefront-customers-table').includesText('ava.chen@example.test');
    });
});
