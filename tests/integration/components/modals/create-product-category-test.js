import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
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
        assert.dom(this.element).includesText('Lunch');
    });
});
