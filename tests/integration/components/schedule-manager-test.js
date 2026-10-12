import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | schedule-manager', function (hooks) {
    setupRenderingTest(hooks);

    test('it buckets hours by day regardless of the day name casing', async function (assert) {
        this.set('subject', {
            id: 'loc_1',
            hours: [
                { id: 'h1', day_of_week: 'monday', humanReadableHours: '08:00 AM - 09:00 PM' },
                { id: 'h2', day_of_week: 'Tuesday', humanReadableHours: '10:00 AM - 02:00 PM' },
            ],
        });

        await render(hbs`<ScheduleManager @subject={{this.subject}} @subjectKey="store_location_uuid" @hourModelType="store-hour" />`);

        const days = this.element.querySelectorAll('[data-test-schedule-day]');
        assert.strictEqual(days.length, 7, 'one row per weekday');

        const byDay = {};
        days.forEach((day) => {
            byDay[day.getAttribute('data-test-schedule-day')] = [...day.querySelectorAll('.storefront-schedule__slot')].map((slot) => slot.textContent.trim());
        });

        assert.deepEqual(byDay.Monday, ['08:00 AM - 09:00 PM'], 'lowercase monday lands under Monday');
        assert.deepEqual(byDay.Tuesday, ['10:00 AM - 02:00 PM'], 'capitalised Tuesday lands under Tuesday');
        assert.deepEqual(byDay.Wednesday, [], 'days without hours have no slots');
        assert.dom('[data-test-schedule-day="Monday"] .storefront-schedule__name').hasText('Monday', 'the day is named in the console language');
        assert.dom('[data-test-schedule-add="Monday"]').exists('each day offers Add hours');
    });
});
