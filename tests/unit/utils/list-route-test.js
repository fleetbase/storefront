import { module, test } from 'qunit';
import { realQueryParamChanges } from '@fleetbase/storefront-engine/utils/list-route';

module('Unit | Utility | list-route', function () {
    test('defaults re-sent by a child transition are not changes', function (assert) {
        assert.deepEqual(realQueryParamChanges({ page: '1', sort: '-created_at' }, {}, { page: '1', sort: '-created_at' }), []);
        assert.deepEqual(realQueryParamChanges({ page: '1' }, { page: '1' }, { page: '1' }), []);
    });

    test('a real page, sort or filter change still counts', function (assert) {
        assert.deepEqual(realQueryParamChanges({ page: '2' }, {}, { page: '1' }), ['page']);
        assert.deepEqual(realQueryParamChanges({ page: '1' }, { page: '3' }, { page: '1' }), ['page'], 'going back to page one');
        assert.deepEqual(realQueryParamChanges({ status: 'draft' }, {}, {}), ['status']);
        assert.deepEqual(realQueryParamChanges({ query: '' }, {}, {}), [], 'an empty search equals no search');
    });
});
