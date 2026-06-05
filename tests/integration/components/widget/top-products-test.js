import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class StorefrontStubService extends Service {
    activeStore = { public_id: 'store_1' };
    on() {}
}

module('Integration | Component | widget/top-products', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:storefront', StorefrontStubService);
    });

    test('it renders top product rows', async function (assert) {
        class FetchStubService extends Service {
            get() {
                return {
                    products: [{ id: 'product_1', name: 'Signature Bento', quantity: 7, revenue: 4900, currency: 'USD', primary_image_url: 'https://example.com/bento.png' }],
                };
            }
        }

        this.owner.register('service:fetch', FetchStubService);

        await render(hbs`<Widget::TopProducts />`);

        assert.dom('.storefront-list-row').exists({ count: 1 });
        assert.dom('.storefront-list-row').includesText('Signature Bento');
        assert.dom('.storefront-list-row').includesText('7 sold');
        assert.dom('.storefront-product-list-image').hasAttribute('src', 'https://example.com/bento.png');
    });

    test('it renders an empty state', async function (assert) {
        class FetchStubService extends Service {
            get() {
                return { products: [] };
            }
        }

        this.owner.register('service:fetch', FetchStubService);

        await render(hbs`<Widget::TopProducts />`);

        assert.dom('.storefront-widget-empty').hasText('No product sales yet.');
    });
});
