import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class StoreStubService extends Service {
    query() {
        return [];
    }
}

module('Integration | Component | modals/manage-addons', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:store', StoreStubService);
    });

    test('it renders addon management', async function (assert) {
        this.set('options', {
            store: {
                id: 'store_1',
                currency: 'USD',
            },
        });

        await render(hbs`<Modals::ManageAddons @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom('[data-test-storefront-product-addon-management]').exists();
        assert.dom('[data-test-storefront-product-addon-management]').includesText('Checkout Add-ons');
    });
});
