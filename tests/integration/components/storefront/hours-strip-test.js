import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | storefront/hours-strip', function (hooks) {
    setupRenderingTest(hooks);

    test('it fills the days a schedule covers and labels each with its hours', async function (assert) {
        this.set('hours', [
            { day_of_week: 'Monday', start: '11:00', end: '15:00' },
            { day_of_week: 'tuesday', start: '11:00', end: '15:00' },
            { day_of_week: 'Sat', start: '09:00', end: '12:00' },
            { day_of_week: 'Saturday', start: '17:00', end: '21:00' },
        ]);

        await render(hbs`<Storefront::HoursStrip @hours={{this.hours}} />`);

        assert.dom('[data-test-hours-day]').exists({ count: 7 });
        assert.dom('[data-test-hours-day="monday"]').hasAttribute('data-open', 'true').hasAttribute('title', 'Monday: 11:00 to 15:00');
        assert.dom('[data-test-hours-day="tuesday"]').hasAttribute('data-open', 'true');
        assert.dom('[data-test-hours-day="saturday"]').hasAttribute('title', 'Saturday: 09:00 to 12:00, 17:00 to 21:00');
        assert.dom('[data-test-hours-day="sunday"]').hasAttribute('data-open', 'false').hasAttribute('title', 'Sunday: closed');
        assert.dom('[data-test-hours-strip]').hasAttribute('aria-label', '3 of 7 days');
    });

    test('no hours means seven closed days', async function (assert) {
        await render(hbs`<Storefront::HoursStrip />`);

        assert.dom('[data-test-hours-day][data-open="true"]').doesNotExist();
        assert.dom('[data-test-hours-strip]').hasAttribute('aria-label', '0 of 7 days');
    });
});
