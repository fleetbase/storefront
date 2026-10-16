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

    test('categories nest under their parents, counts roll up, and a category above the third level takes a subcategory', async function (assert) {
        const drinks = { id: 'drinks', name: 'Drinks', parent_uuid: null };
        const coffee = { id: 'coffee', name: 'Coffee', parent_uuid: 'drinks' };
        const coldBrew = { id: 'cold_brew', name: 'Cold brew', parent_uuid: 'coffee' };
        const created = [];

        this.setProperties({
            categories: [coldBrew, coffee, drinks],
            counts: { drinks: 1, coffee: 2, cold_brew: 3 },
            createSubcategory: (category) => created.push(category.id),
            noop() {},
        });

        await render(hbs`<Storefront::Product::CategorySidebar
            @categories={{this.categories}}
            @counts={{this.counts}}
            @onCreate={{this.noop}}
            @onCreateSubcategory={{this.createSubcategory}}
            @onViewAll={{this.noop}}
            @onSelect={{this.noop}}
            @onSelectGroup={{this.noop}}
        />`);

        assert.dom('[data-test-storefront-product-category="cold_brew"]').hasClass('storefront-product-category--depth-2');
        assert.dom('[data-test-storefront-product-category="drinks"] .storefront-product-category__count').hasText('6', 'a parent counts everything under it');
        assert.dom('[data-test-storefront-product-subcategory="coffee"]').exists('a second-level category takes a subcategory');
        assert.dom('[data-test-storefront-product-subcategory="cold_brew"]').doesNotExist('a third-level category takes none');

        await click('[data-test-storefront-product-subcategory="drinks"]');
        assert.deepEqual(created, ['drinks']);
    });
});
