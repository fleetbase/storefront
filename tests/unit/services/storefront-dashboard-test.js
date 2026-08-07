import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | storefront-dashboard', function (hooks) {
    setupTest(hooks);

    test('it defaults to the last 30 days', function (assert) {
        const service = this.owner.lookup('service:storefront-dashboard');

        assert.strictEqual(service.label, 'Last 30 Days');
        assert.ok(service.start, 'start date is set');
        assert.ok(service.end, 'end date is set');
        assert.deepEqual(service.queryParams, { start: service.start, end: service.end });
        assert.deepEqual(service.withStore('store_1'), { store: 'store_1', start: service.start, end: service.end });
    });

    test('it updates from date picker selections', function (assert) {
        assert.expect(5);

        const service = this.owner.lookup('service:storefront-dashboard');
        const done = assert.async();

        service.on('periodChanged', (queryParams) => {
            assert.deepEqual(queryParams, { start: '2026-05-01', end: '2026-05-31' });
            assert.strictEqual(service.version, 1);
            done();
        });

        service.selectDates({ formattedDate: ['2026-05-01', '2026-05-31'] });

        assert.strictEqual(service.label, 'Custom');
        assert.strictEqual(service.start, '2026-05-01');
        assert.strictEqual(service.end, '2026-05-31');
    });

    test('it updates from quick-select ranges', function (assert) {
        const service = this.owner.lookup('service:storefront-dashboard');

        service.selectRange({
            label: 'Last 7 Days',
            getValue() {
                return [new Date(2026, 4, 25), new Date(2026, 4, 31)];
            },
        });

        assert.strictEqual(service.label, 'Last 7 Days');
        assert.strictEqual(service.start, '2026-05-25');
        assert.strictEqual(service.end, '2026-05-31');
        assert.strictEqual(service.formattedRange, 'May 25, 2026 - May 31, 2026');
    });

    test('date picker presets select silently and emit one labeled period change', function (assert) {
        const service = this.owner.lookup('service:storefront-dashboard');
        const button = service.datePickerButtons.find((candidate) => candidate.content === 'Last 7 Days');
        const calls = [];
        let changes = 0;
        const datepicker = {
            selectDate(dates, options) {
                calls.push({ dates, options });
            },
            hide() {
                calls.push({ hidden: true });
            },
        };

        service.on('periodChanged', () => changes++);
        button.onClick(datepicker);

        assert.strictEqual(service.label, 'Last 7 Days');
        assert.strictEqual(changes, 1, 'refreshes dashboard widgets once');
        assert.strictEqual(calls[0].dates.length, 2, 'selects a complete range');
        assert.deepEqual(calls[0].options, { silent: true }, 'suppresses the delayed AirDatepicker onSelect callback');
        assert.deepEqual(calls[1], { hidden: true }, 'closes the picker after applying the range');
    });
});
