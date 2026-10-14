import promotionLifecycle from '@fleetbase/storefront-engine/utils/promotion-lifecycle';
import { module, test } from 'qunit';

module('Unit | Utility | promotion-lifecycle', function () {
    const now = new Date('2026-10-12T00:00:00Z');

    test('an active promotion past its end has ended', function (assert) {
        assert.strictEqual(promotionLifecycle({ status: 'active', starts_at: '2026-08-29T00:00:00Z', ends_at: '2026-09-29T00:00:00Z' }, now), 'ended');
        assert.strictEqual(promotionLifecycle({ status: 'paused', ends_at: '2026-09-29T00:00:00Z' }, now), 'ended', 'any status, as the server reads it');
    });

    test('an active promotion before its start is scheduled', function (assert) {
        assert.strictEqual(promotionLifecycle({ status: 'active', starts_at: '2026-11-01T00:00:00Z' }, now), 'scheduled');
        assert.strictEqual(promotionLifecycle({ status: 'draft', starts_at: '2026-11-01T00:00:00Z' }, now), 'draft', 'a draft stays a draft');
    });

    test('otherwise it keeps its status', function (assert) {
        assert.strictEqual(promotionLifecycle({ status: 'active', starts_at: '2026-10-01T00:00:00Z', ends_at: '2026-11-01T00:00:00Z' }, now), 'active');
        assert.strictEqual(promotionLifecycle({ status: 'paused' }, now), 'paused');
        assert.strictEqual(promotionLifecycle({ status: 'ended' }, now), 'ended');
        assert.strictEqual(promotionLifecycle({}, now), 'draft');
        assert.strictEqual(promotionLifecycle(null, now), 'draft');
    });
});
