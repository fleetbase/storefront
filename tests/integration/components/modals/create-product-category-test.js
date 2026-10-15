import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { fillIn, render, settled } from '@ember/test-helpers';
import { run } from '@ember/runloop';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/create-product-category', function (hooks) {
    setupRenderingTest(hooks);

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

    test('it edits a catalog category name, description and icon', async function (assert) {
        const store = this.owner.lookup('service:store');
        const category = run(() => store.createRecord('catalog-category', { name: 'Burgers', description: 'Old', icon_url: 'https://cdn.test/burgers.png' }));
        this.set('options', { category, uploadNewPhoto() {} });

        await render(hbs`<Modals::CreateProductCategory @modalIsOpened={{true}} @options={{this.options}} />`);

        const [name, description] = this.element.querySelectorAll('input[type="text"]');
        assert.dom(name).hasValue('Burgers');
        assert.dom(description).hasValue('Old');
        assert.dom('.storefront-product-category-form__image').hasAttribute('src', 'https://cdn.test/burgers.png');

        await fillIn(description, 'Smash burgers');
        assert.strictEqual(category.description, 'Smash burgers', 'the form writes to the catalog category');

        run(() => category.set('icon_url', 'https://cdn.test/new.png'));
        await settled();
        assert.dom('.storefront-product-category-form__image').hasAttribute('src', 'https://cdn.test/new.png', 'an uploaded icon shows at once');
    });
});
