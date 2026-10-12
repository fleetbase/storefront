import { module, test } from 'qunit';
import Service from '@ember/service';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | storefront/product/category-sidebar', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        // the sidebar gates its buttons on abilities; grant everything here
        this.owner.register(
            'service:abilities',
            class AbilitiesStub extends Service {
                can() {
                    return true;
                }

                cannot() {
                    return false;
                }
            }
        );
    });

    test('it renders and selects product categories', async function (assert) {
        assert.expect(5);

        const lunch = { id: 'cat_1', name: 'Lunch', description: 'Lunch menu' };
        const drinks = { id: 'cat_2', name: 'Drinks', description: 'Beverages' };

        this.setProperties({
            categories: [lunch, drinks],
            activeCategory: lunch,
            selectCategory(category) {
                assert.strictEqual(category, drinks);
            },
            viewAll() {
                assert.ok(true, 'view all callback fired');
            },
            createCategory() {},
        });

        await render(hbs`
            <Storefront::Product::CategorySidebar
                @categories={{this.categories}}
                @activeCategory={{this.activeCategory}}
                @onCreate={{this.createCategory}}
                @onViewAll={{this.viewAll}}
                @onSelect={{this.selectCategory}}
            />
        `);

        assert.dom('[data-test-storefront-product-category-sidebar]').exists();
        assert.dom('[data-test-storefront-product-category-sidebar]').includesText('All products');
        assert.dom('[data-test-storefront-product-category-sidebar]').includesText('Lunch');
        assert.dom('[data-test-storefront-product-category-sidebar]').includesText('Drinks');

        await click('[data-test-storefront-product-category="cat_2"]');
    });
});
