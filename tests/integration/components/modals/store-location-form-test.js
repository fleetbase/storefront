import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/store-location-form', function (hooks) {
    setupRenderingTest(hooks);

    // Modals read their @options (network, store, catalog) in tasks on render; a bare render has nothing to assert.
    test.skip('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Modals::StoreLocationForm />`);

        assert.dom(this.element).exists();

        // Template block usage:
        await render(hbs`<Modals::StoreLocationForm />`);
        assert.dom(this.element).exists();
    });
});
