import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, select } from '@ember/test-helpers';
import EmberObject from '@ember/object';
import { setupIntl } from 'ember-intl/test-support';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/create-product-category', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('it renders the category form', async function (assert) {
        this.set('options', {
            category: {
                name: 'Lunch',
                description: 'Lunch menu',
                icon_url: 'https://example.com/lunch.png',
            },
            uploadNewPhoto() {},
        });

        await render(hbs`<Modals::CreateProductCategory @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('.storefront-product-category-form__media').exists();
        assert.dom('input[type="text"]').hasValue('Lunch');
    });

    test("given the store's categories, the form offers the parents a category can go under", async function (assert) {
        // Drinks › Coffee › Cold brew, and Food; Coffee is being edited.
        const drinks = { id: 'drinks', name: 'Drinks', parent_uuid: null };
        const coffee = EmberObject.create({ id: 'coffee', name: 'Coffee', parent_uuid: 'drinks' });
        const coldBrew = { id: 'cold_brew', name: 'Cold brew', parent_uuid: 'coffee' };
        const food = { id: 'food', name: 'Food', parent_uuid: null };
        this.set('options', { category: coffee, categories: [drinks, coffee, coldBrew, food], uploadNewPhoto() {} });

        await render(hbs`<Modals::CreateProductCategory @modalIsOpened={{true}} @options={{this.options}} />`);

        const labels = [...this.element.querySelectorAll('[data-test-category-parent] option')].map((option) => option.textContent.trim());
        // Not itself, not Cold brew under it, and nothing that would put Cold brew past the third level.
        assert.deepEqual(labels, ['None (top level)', 'Drinks', 'Food']);
        assert.dom('[data-test-category-parent]').hasValue('drinks');

        await select('[data-test-category-parent]', 'food');
        assert.strictEqual(coffee.parent_uuid, 'food');
        assert.strictEqual(coffee.parent, food, 'the parent is set with its id, as the serializer writes it');

        await select('[data-test-category-parent]', '');
        assert.strictEqual(coffee.parent_uuid, null, 'back to the top level');
    });
});
