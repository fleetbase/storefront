import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | storefront/product/card', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders product catalog details', async function (assert) {
        this.set('product', {
            id: 'product_1',
            name: 'Signature Bento',
            description: 'A complete lunch set',
            sku: 'BENTO-1',
            status: 'published',
            price: 1200,
            sale_price: 990,
            currency: 'USD',
            is_available: true,
            is_on_sale: true,
            is_service: false,
            is_bookable: false,
            is_recommended: true,
            updatedAgo: '2 days',
            primary_image_url: 'https://example.com/bento.png',
            category: { name: 'Lunch' },
            variants: [{ name: 'Size' }],
            addon_categories: [{ name: 'Sides' }],
        });
        this.set('deleteProduct', () => {});

        await render(hbs`
            <Storefront::Product::Card
                @product={{this.product}}
                @editRoute="index"
                @onDelete={{this.deleteProduct}}
            />
        `);

        assert.dom('[data-test-storefront-product-card]').exists();
        assert.dom('[data-test-storefront-product-card]').includesText('Signature Bento');
        assert.dom('[data-test-storefront-product-card]').includesText('Lunch');
        assert.dom('[data-test-storefront-product-card]').includesText('BENTO-1');
        assert.dom('[data-test-storefront-product-card]').includesText('1 variants');
        assert.dom('[data-test-storefront-product-card]').includesText('1 add-ons');
        assert.dom('[data-test-storefront-product-card]').includesText('Recommended');
    });

    test('it renders legacy available status as published', async function (assert) {
        this.set('product', {
            id: 'product_1',
            name: 'Legacy Product',
            description: 'Older product record',
            sku: 'LEGACY-1',
            status: 'available',
            price: 1200,
            currency: 'USD',
            is_available: true,
            primary_image_url: 'https://example.com/legacy.png',
            variants: [],
            addon_categories: [],
        });
        this.set('deleteProduct', () => {});

        await render(hbs`
            <Storefront::Product::Card
                @product={{this.product}}
                @editRoute="index"
                @onDelete={{this.deleteProduct}}
            />
        `);

        assert.dom('[data-test-storefront-product-card]').includesText('Published');
        assert.dom('[data-test-storefront-product-card]').doesNotIncludeText('Available');
    });
});
