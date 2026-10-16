import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, select } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/add-store-to-category', function (hooks) {
    setupRenderingTest(hooks);

    // Modals read their @options (network, store, catalog) in tasks on render; a bare render has nothing to assert.
    test.skip('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Modals::AddStoreToCategory />`);

        assert.dom(this.element).exists();

        // Template block usage:
        await render(hbs`<Modals::AddStoreToCategory />`);
        assert.dom(this.element).exists();
    });

    test("moving products offers the store's categories in tree order", async function (assert) {
        const picked = [];
        const drinks = { id: 'drinks', name: 'Drinks', parent_uuid: null };
        const coffee = { id: 'coffee', name: 'Coffee', parent_uuid: 'drinks' };
        this.set('options', { categories: [coffee, drinks], category: null, setCategory: (category) => picked.push(category) });

        await render(hbs`<Modals::AddStoreToCategory @modalIsOpened={{true}} @options={{this.options}} />`);

        const labels = [...this.element.querySelectorAll('[data-test-category-tree-select] option:not([disabled])')].map((option) => option.textContent.trim());
        assert.deepEqual(labels, ['Drinks', 'Drinks › Coffee']);

        await select('[data-test-category-tree-select]', 'coffee');
        assert.deepEqual(picked, [coffee]);
    });
});
