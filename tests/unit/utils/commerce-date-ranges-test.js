import { predefinedDateRanges } from 'dummy/utils/commerce-date-ranges';
import { module, test } from 'qunit';

module('Unit | Utility | commerce-date-ranges', function () {
    test('it uses a compact current-year preset list', function (assert) {
        const currentYear = new Date().getFullYear();
        const labels = predefinedDateRanges.map((range) => range.label);

        assert.ok(labels.includes('Last 7 Days'), 'includes short rolling range');
        assert.ok(labels.includes('Last 30 Days'), 'includes default rolling range');
        assert.ok(labels.includes('Last 90 Days'), 'includes longer rolling range');
        assert.ok(labels.includes('This Quarter'), 'includes quarter reporting');
        assert.ok(labels.includes(`Q1 ${currentYear}`), 'includes current-year quarterly presets');
        assert.ok(labels.includes('Black Friday Week'), 'includes dynamic commerce season');
        assert.notOk(
            labels.some((label) => label.includes('2024')),
            'does not include stale fixed-year presets'
        );
        assert.notOk(
            labels.some((label) => label.includes('2023')),
            'does not include older fixed-year presets'
        );
    });
});
