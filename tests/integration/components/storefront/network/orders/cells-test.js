import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { initialize } from '@fleetbase/storefront-engine/instance-initializers/register-storefront-resource-descriptors';

module('Integration | Component | storefront/network/orders cells', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        initialize(this.owner);
        this.set('storesById', {
            store_a: { public_id: 'store_a', name: 'Rochor Noodle House', currency: 'SGD', logo_url: 'https://cdn.test/a.png' },
            store_b: { public_id: 'store_b', name: 'Orchard Grocers', currency: 'SGD', logo_url: 'https://cdn.test/b.png' },
        });
    });

    test('one store reads as a single-line pill, without the currency underneath', async function (assert) {
        this.set('row', { meta: { storefront_id: 'store_a', storefront: 'Rochor Noodle House' } });

        await render(hbs`<Storefront::Network::Orders::Cell::Stores @row={{this.row}} @column={{hash storesById=this.storesById}} />`);

        assert.dom('[data-test-resource-pill-title]').hasText('Rochor Noodle House');
        assert.dom('[data-test-resource-pill-subtitle]').doesNotExist();
        assert.dom('[data-test-order-store-avatar]').doesNotExist();
    });

    test('a checkout across stores shows one round logo per store, named for screen readers', async function (assert) {
        this.set('row', { isGroup: true, storeIds: ['store_a', 'store_b', 'store_x'], storeNames: ['Rochor Noodle House', 'Orchard Grocers', 'Unknown Mart'] });

        await render(hbs`<Storefront::Network::Orders::Cell::Stores @row={{this.row}} @column={{hash storesById=this.storesById}} />`);

        assert.dom('[data-test-order-stores]').hasClass('storefront-identity-stack--avatars');
        assert.dom('[data-test-order-store-avatar]').exists({ count: 2 });
        assert.dom('[data-test-order-store-avatar]').hasAttribute('aria-label', 'Rochor Noodle House');
        assert.dom('[data-test-pill-online-indicator]').doesNotExist();
        assert.dom('.storefront-identity-stack__initial').hasText('U', 'a store that is not loaded falls back to its initial');
        assert.dom('.storefront-identity-stack__initial').hasAttribute('title', 'Unknown Mart');
    });

    test('an order reference shows its item count, not the checkout it belongs to', async function (assert) {
        this.set('row', { public_id: 'order_1', item_count: 2, meta: { checkout_id: 'chkt_123' } });
        this.set('column', { onView() {}, onToggle() {} });

        await render(hbs`<Storefront::Network::Orders::Cell::Reference @row={{this.row}} @column={{this.column}} />`);

        assert.dom('[data-test-order-reference]').includesText('order_1');
        assert.dom('[data-test-order-reference]').doesNotIncludeText('chkt_123');
        assert.dom('.storefront-order-ref__meta').hasText('2 items');
    });
});
