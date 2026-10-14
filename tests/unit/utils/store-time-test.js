import { module, test } from 'qunit';
import { formatInStore, isSameStoreDay, storeClock, storeOffset, toStoreWall } from '@fleetbase/storefront-engine/utils/store-time';
import storeOpenState from '@fleetbase/storefront-engine/utils/store-open-state';

module('Unit | Utility | store-time', function () {
    // 06:07 UTC on Wednesday 14 Oct 2026 is 14:07 in a UTC+8 store.
    const instant = new Date(Date.UTC(2026, 9, 14, 6, 7));

    test('times read on the store clock from its offset, whatever the browser zone', function (assert) {
        assert.strictEqual(formatInStore(instant, 'EEE d MMM HH:mm', 480), 'Wed 14 Oct 14:07');
        assert.strictEqual(formatInStore(instant, 'HH:mm', 0), '06:07');
        assert.strictEqual(formatInStore(instant, 'EEE HH:mm', -300), 'Wed 01:07');
        assert.strictEqual(formatInStore('2026-10-14T06:07:00Z', 'HH:mm', 480), '14:07', 'ISO strings too');
        assert.strictEqual(formatInStore(null, 'HH:mm', 480), '');
    });

    test('the store clock gives its weekday and minutes', function (assert) {
        assert.deepEqual(storeClock(480, instant), { day: 3, minutes: 14 * 60 + 7 });
        assert.deepEqual(storeClock(-420, instant), { day: 2, minutes: 23 * 60 + 7 }, 'the day before west of UTC');
    });

    test('same day is the store calendar day', function (assert) {
        const late = new Date(Date.UTC(2026, 9, 14, 17, 0)); // 01:00 on the 15th at UTC+8
        assert.true(isSameStoreDay(instant, instant, 480));
        assert.false(isSameStoreDay(instant, late, 480));
        assert.true(isSameStoreDay(instant, late, 0));
        assert.strictEqual(toStoreWall(late, 480).getDate(), 15);
    });

    test('the offset comes from the API first', function (assert) {
        assert.strictEqual(storeOffset({ utc_offset: 480, timezone: 'America/New_York' }), 480);
        assert.strictEqual(storeOffset({ utc_offset: 0 }), 0);
        assert.strictEqual(storeOffset(null), -new Date().getTimezoneOffset(), 'no store: the browser clock');
    });

    test('a store open 09:00 to 21:00 is open at 14:07 its time', function (assert) {
        const hours = [{ day_of_week: 'wednesday', start: '09:00', end: '21:00' }];
        assert.true(storeOpenState(hours, { offset: 480, now: instant }).isOpen);
        const before = storeOpenState(hours, { offset: 0, now: instant });
        assert.false(before.isOpen, 'at 06:07 on a UTC clock it is not');
        assert.strictEqual(before.opensAt, '09:00');
    });
});
