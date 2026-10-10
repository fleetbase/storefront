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

        const panels = this.element.querySelectorAll('.next-content-panel');
        assert.strictEqual(panels.length, 7, 'one panel per weekday');

        const byDay = {};
        panels.forEach((panel) => {
            byDay[panel.querySelector('h4').textContent.trim()] = panel.querySelector('.next-content-panel-body').textContent;
        });

        assert.ok(byDay.Monday.includes('08:00 AM - 09:00 PM'), 'lowercase monday lands under Monday');
        assert.ok(byDay.Tuesday.includes('10:00 AM - 02:00 PM'), 'capitalised Tuesday lands under Tuesday');
        assert.notOk(byDay.Wednesday.trim(), 'days without hours stay empty');
    });
});
