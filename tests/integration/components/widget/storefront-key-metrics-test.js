import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | widget/storefront-key-metrics', function (hooks) {
    setupRenderingTest(hooks);

    // The widget fetches analytics on render; a bare render issues a real request and aborts the run.
    test.skip('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Widget::StorefrontKeyMetrics />`);

        assert.dom(this.element).exists();

        // Template block usage:
        await render(hbs`<Widget::StorefrontKeyMetrics />`);
        assert.dom(this.element).exists();
    });
});
