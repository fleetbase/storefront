import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | storefront/catalog/card', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('it renders the catalog, its categories with counts, its hours and its product total', async function (assert) {
        const edited = [];
        this.set('catalog', {
            name: 'Lunch menu',
            description: 'Weekday lunch service',
            status: 'published',
            updatedAgo: '5 days',
            categories: [
                { name: 'Mains', products: [{}, {}, {}] },
                { name: 'Drinks', products: [{}] },
            ],
            hours: [{ day_of_week: 'Monday', start: '11:00', end: '15:00' }],
        });
        this.set('edit', (catalog) => edited.push(catalog));
        this.set('remove', () => {});

        await render(hbs`<Storefront::Catalog::Card @catalog={{this.catalog}} @onEdit={{this.edit}} @onDelete={{this.remove}} />`);

        assert.dom('[data-test-storefront-catalog-card]').exists().doesNotHaveClass('is-draft');
        assert.dom('[data-test-catalog-name]').hasText('Lunch menu');
        assert.dom('[data-test-catalog-status]').includesText('Published');
        assert.dom('[data-test-catalog-category]').exists({ count: 2 });
        assert.dom('[data-test-catalog-category]:first-child').hasText('Mains 3');
        assert.dom('[data-test-catalog-products-count]').hasText('4 products');
        assert.dom('[data-test-hours-day="monday"]').hasAttribute('data-open', 'true');
        assert.dom('[data-test-catalog-edit]').exists();
        assert.dom('[data-test-catalog-delete]').exists();

        await click('[data-test-catalog-name]');
        assert.strictEqual(edited[0], this.catalog, 'the name opens the editor');
    });

    test('a draft catalog with nothing in it says so', async function (assert) {
        this.set('catalog', { name: 'Festive hampers', status: 'draft', categories: [], hours: [] });

        await render(hbs`<Storefront::Catalog::Card @catalog={{this.catalog}} />`);

        assert.dom('[data-test-storefront-catalog-card]').hasClass('is-draft');
        assert.dom('[data-test-catalog-empty]').hasText('No categories yet');
        assert.dom('[data-test-catalog-products-count]').hasText('No products');
        assert.dom('[data-test-catalog-edit]').doesNotExist('no edit handler, no edit button');
    });
});
