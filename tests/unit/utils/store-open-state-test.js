import storeOpenState, { formatClock } from 'dummy/utils/store-open-state';
import { module, test } from 'qunit';

const hours = [
    { day_of_week: 'monday', start: '08:00:00', end: '21:00:00' },
    { day_of_week: 'tuesday', start: '08:00:00', end: '21:00:00' },
    { day_of_week: 'saturday', start: '10:00:00', end: '14:00:00' },
];

// Mondays in UTC
const mondayNoon = new Date('2026-10-12T12:00:00Z');
const mondayLate = new Date('2026-10-12T22:30:00Z');
const mondayEarly = new Date('2026-10-12T06:00:00Z');
const sunday = new Date('2026-10-11T12:00:00Z');

module('Unit | Utility | store-open-state', function () {
    test('formats clock strings without seconds', function (assert) {
        assert.strictEqual(formatClock('21:00:00'), '21:00');
        assert.strictEqual(formatClock('8:05'), '08:05');
        assert.strictEqual(formatClock(null), null);
    });

    test("is open inside today's hours and knows when it closes", function (assert) {
        const state = storeOpenState(hours, { timezone: 'UTC', now: mondayNoon });

        assert.true(state.hasHours);
        assert.true(state.isOpen);
        assert.strictEqual(state.closesAt, '21:00');
    });

    test('is closed before opening and reports the opening time', function (assert) {
        const state = storeOpenState(hours, { timezone: 'UTC', now: mondayEarly });

        assert.false(state.isOpen);
        assert.strictEqual(state.opensAt, '08:00');
        assert.strictEqual(state.opensDay, null, 'opens later today');
    });

    test('is closed after hours and points at the next open day', function (assert) {
        const state = storeOpenState(hours, { timezone: 'UTC', now: mondayLate });

        assert.false(state.isOpen);
        assert.strictEqual(state.opensDay, 'tuesday');
        assert.strictEqual(state.opensAt, '08:00');
    });

    test('skips days without hours when finding the next opening', function (assert) {
        const state = storeOpenState(hours, { timezone: 'UTC', now: sunday });

        assert.strictEqual(state.opensDay, 'monday');
    });

    test('evaluates in the store timezone', function (assert) {
        // 12:00 UTC is 20:00 in Singapore on the same Monday: still open, closes at 21:00
        const singapore = storeOpenState(hours, { timezone: 'Asia/Singapore', now: mondayNoon });
        assert.true(singapore.isOpen);

        // 14:00 UTC is 22:00 in Singapore: closed until Tuesday
        const later = storeOpenState(hours, { timezone: 'Asia/Singapore', now: new Date('2026-10-12T14:00:00Z') });
        assert.false(later.isOpen);
        assert.strictEqual(later.opensDay, 'tuesday');
    });

    test('reports no hours when nothing is configured', function (assert) {
        const state = storeOpenState([], { timezone: 'UTC', now: mondayNoon });

        assert.false(state.hasHours);
        assert.false(state.isOpen);
    });
});
