import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class StorefrontStubService extends Service {
    activeStore = { public_id: 'store_1', currency: 'USD' };
    on() {}
}

class FetchStubService extends Service {
    get() {
        return [];
    }
}

class IntlStubService extends Service {
    t(key) {
        if (key === 'storefront.component.widget.orders.widget-title') {
            return 'Recent Orders';
        }

        return key;
    }
}

class AppCacheStubService extends Service {
    setEmberData() {}
}

module('Integration | Component | widget/orders', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:storefront', StorefrontStubService);
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:intl', IntlStubService);
        this.owner.register('service:app-cache', AppCacheStubService);
    });

    test('it renders the polished empty table shell', async function (assert) {
        await render(hbs`<Widget::Orders />`);

        assert.dom('.storefront-orders-widget').exists();
        assert.dom('.storefront-orders-header').includesText('Recent Orders');
        assert.dom('.storefront-orders-table').exists();
        assert.dom('.storefront-widget-empty').hasText('No recent orders yet.');
    });
});
