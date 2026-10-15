import { module, test } from 'qunit';
import { groupOrdersByCheckout, orderItemCount, summarizeGroup } from '@fleetbase/storefront-engine/utils/order-groups';

module('Unit | Utility | order-groups', function () {
    const order = (overrides = {}) => ({
        public_id: 'order_1',
        status: 'created',
        customer_name: 'Ava',
        payload: { entities_count: 2 },
        meta: { checkout_id: 'chkt_1', storefront_id: 'store_1', storefront: 'Orchard Grocers', total: 10, is_pickup: false },
        ...overrides,
    });

    test('an order counts its items from the payload the API lists', function (assert) {
        assert.strictEqual(orderItemCount(order()), 2);
        assert.strictEqual(orderItemCount(order({ payload: null, item_count: 3 })), 3);
        assert.strictEqual(orderItemCount(order({ payload: null })), 0);
    });

    test('orders of one checkout become a group with the summed items and the least-advanced status', function (assert) {
        const rows = groupOrdersByCheckout([
            order({ public_id: 'order_1', status: 'ready' }),
            order({ public_id: 'order_2', status: 'created', payload: { entities_count: 3 }, meta: { checkout_id: 'chkt_1', storefront_id: 'store_2', total: 5 } }),
            order({ public_id: 'order_3', meta: { checkout_id: 'chkt_2' } }),
            order({ public_id: 'order_4', meta: {} }),
        ]);

        assert.strictEqual(rows.length, 3, 'a group, a single-order checkout and an order without a checkout');
        const [group, single, loose] = rows;
        assert.true(group.isGroup);
        assert.strictEqual(group.id, 'chkt_1');
        assert.strictEqual(group.orderCount, 2);
        assert.strictEqual(group.itemCount, 5);
        assert.strictEqual(group.status, 'created', 'the least-advanced child sets the group status');
        assert.deepEqual(group.storeIds, ['store_1', 'store_2']);
        assert.strictEqual(group.total, 15);
        assert.strictEqual(single.public_id, 'order_3', 'alone in its checkout stays a plain order');
        assert.strictEqual(loose.public_id, 'order_4');
    });

    test('a group summary reports how many children sit at the lowest status', function (assert) {
        const group = summarizeGroup({ id: 'chkt_9', isGroup: true, orders: [order({ status: 'preparing' }), order({ status: 'completed' }), order({ status: 'preparing' })] });

        assert.deepEqual(group.statusSummary, { count: 2, total: 3, status: 'preparing' });
        assert.false(group.allTerminal);
    });

    test('a checkout from one food truck carries the truck, a mixed one does not', function (assert) {
        const truck = { id: 'food_truck_one', name: 'Taco Truck' };
        const fromTruck = (overrides = {}) => order({ meta: { checkout_id: 'chkt_1', food_truck_id: 'food_truck_one', food_truck: truck }, ...overrides });

        assert.deepEqual(summarizeGroup({ id: 'chkt_1', isGroup: true, orders: [fromTruck(), fromTruck()] }).food_truck, truck);
        assert.strictEqual(summarizeGroup({ id: 'chkt_1', isGroup: true, orders: [fromTruck(), order()] }).food_truck, null, 'a truck order with a store order');
        assert.strictEqual(summarizeGroup({ id: 'chkt_1', isGroup: true, orders: [order(), order()] }).food_truck, null, 'store orders only');
    });
});
