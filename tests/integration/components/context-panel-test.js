import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | context-panel', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<ContextPanel />`);

        assert.dom().hasText('');

        // Template block usage:
        await render(hbs`
      <ContextPanel>
        template block text
      </ContextPanel>
    `);

        assert.dom().hasText('template block text');
    });
});
