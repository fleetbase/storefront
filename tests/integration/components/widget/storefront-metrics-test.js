import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | widget/storefront-metrics', function (hooks) {
    setupRenderingTest(hooks);

    // The widget fetches analytics on render; a bare render has nothing to assert and tears down mid-request.
    test.skip('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Widget::StorefrontMetrics />`);

        assert.dom(this.element).exists();

        // Template block usage:
        await render(hbs`<Widget::StorefrontMetrics />`);
        assert.dom(this.element).exists();
    });
});
