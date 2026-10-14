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
        assert.deepEqual(realQueryParamChanges({ status: 'draft' }, {}, { status: '' }), ['status']);
        assert.deepEqual(realQueryParamChanges({ query: '' }, {}, { query: '' }), [], 'an empty search equals no search');
    });

    test("a child panel's own params are not list changes", function (assert) {
        // Opening New booking re-sends the list's params and adds the panel's `booking`.
        assert.deepEqual(
            realQueryParamChanges({ page: '1', sort: '-created_at', for_customer: null, booking: '1' }, { page: '1', sort: '-created_at' }, { page: '1', sort: '-created_at' }),
            []
        );
        assert.deepEqual(realQueryParamChanges({ page: '2', booking: '1' }, { page: '1' }, { page: '1' }), ['page'], 'a real list change beside a panel param still counts');
    });
});
