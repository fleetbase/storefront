import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Helper | get-tip-amount', function (hooks) {
    setupRenderingTest(hooks);

    test('a percentage tip is taken from the subtotal and formatted in the currency', async function (assert) {
        await render(hbs`{{get-tip-amount "10%" 5000 "USD"}}`);

        assert.dom(this.element).hasText('$5.00');
    });

    test('a fixed tip is formatted as is', async function (assert) {
        await render(hbs`{{get-tip-amount 350 5000 "USD"}}`);

        assert.dom(this.element).hasText('$3.50');
    });
});
