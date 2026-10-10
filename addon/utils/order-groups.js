import { get } from '@ember/object';

/**
 * Where an order sits in the fulfillment flow, so a group can report its least-advanced child.
 */
export const STATUS_RANK = {
    created: 0,
    pending: 0,
    accepted: 1,
    preparing: 1,
    ready: 2,
    pickup_ready: 2,
    dispatched: 3,
    driver_enroute: 3,
    en_route: 3,
    out_for_delivery: 3,
    completed: 4,
    picked_up: 4,
    canceled: 5,
    order_canceled: 5,
};

/**
 * The status tabs the network orders page offers and the statuses each one queries.
 */
export const STATUS_TABS = [
    { id: 'all', statuses: [] },
    { id: 'needs-action', statuses: ['created', 'pending'] },
    { id: 'preparing', statuses: ['accepted', 'preparing'] },
    { id: 'ready', statuses: ['ready', 'pickup_ready'] },
    { id: 'dispatched', statuses: ['dispatched', 'driver_enroute', 'en_route', 'out_for_delivery'] },
    { id: 'completed', statuses: ['completed', 'picked_up'] },
    { id: 'canceled', statuses: ['canceled', 'order_canceled'] },
];

export function rankOf(status) {
    return STATUS_RANK[String(status ?? '').toLowerCase()] ?? 1;
}

function number(value) {
    const parsed = parseFloat(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

export function orderStoreId(order) {
    return get(order, 'meta.storefront_id') ?? get(order, 'meta.storefront.public_id') ?? null;
}

export function orderStoreName(order) {
    const storefront = get(order, 'meta.storefront');

    return typeof storefront === 'string' ? storefront : (storefront?.name ?? null);
}

export function groupKeyOf(order) {
    return get(order, 'meta.checkout_id') ?? get(order, 'meta.master_order_id') ?? null;
}

/**
 * Groups the orders of one checkout into a single row. An order without a checkout, or
 * alone in its checkout, stays a plain order row.
 *
 * @param {Array} orders
 * @returns {Array} group rows and order rows in the orders' original sequence
 */
export function groupOrdersByCheckout(orders = []) {
    const list = orders?.toArray?.() ?? Array.from(orders ?? []);
    const groups = new Map();
    const rows = [];

    list.forEach((order) => {
        const key = groupKeyOf(order);

        if (!key) {
            rows.push(order);
            return;
        }

        if (!groups.has(key)) {
            const group = { id: key, isGroup: true, orders: [], expanded: false };
            groups.set(key, group);
            rows.push(group);
        }

        groups.get(key).orders.push(order);
    });

    return rows.map((row) => {
        if (!row.isGroup) {
            return row;
        }

        if (row.orders.length === 1) {
            return row.orders[0];
        }

        return summarizeGroup(row);
    });
}

export function summarizeGroup(group) {
    const { orders } = group;
    const first = orders[0];
    const statuses = orders.map((order) => String(order.status ?? '').toLowerCase());
    const leastAdvanced = orders.reduce((lowest, order) => (rankOf(order.status) < rankOf(lowest.status) ? order : lowest), first);
    const leastRank = rankOf(leastAdvanced.status);
    const atLeast = orders.filter((order) => rankOf(order.status) === leastRank).length;
    const drivers = orders.map((order) => order.driver_assigned ?? null).filter(Boolean);
    const driverIds = new Set(orders.map((order) => order.driver_assigned_uuid ?? order.driver_assigned?.id).filter(Boolean));
    const currency = get(first, 'meta.currency') ?? first.currency;

    return Object.assign(group, {
        public_id: group.id,
        orderCount: orders.length,
        itemCount: orders.reduce((sum, order) => sum + number(order.item_count ?? order.total_entities), 0),
        customer: first.customer ?? null,
        customer_name: first.customer_name ?? first.customer?.name ?? null,
        customer_type: first.customer_type,
        storeIds: [...new Set(orders.map(orderStoreId).filter(Boolean))],
        storeNames: [...new Set(orders.map(orderStoreName).filter(Boolean))],
        is_pickup: orders.every((order) => get(order, 'meta.is_pickup')),
        total: orders.reduce((sum, order) => sum + number(get(order, 'meta.total') ?? order.transaction_amount), 0),
        tip: orders.reduce((sum, order) => sum + number(get(order, 'meta.tip')) + number(get(order, 'meta.delivery_tip')), 0),
        currency,
        driver: driverIds.size === 1 ? (drivers[0] ?? null) : null,
        driverCount: driverIds.size,
        status: leastAdvanced.status,
        statuses,
        statusSummary: { count: atLeast, total: orders.length, status: leastAdvanced.status },
        created_at: first.created_at,
        createdAtShort: first.createdAtShort,
        allTerminal: orders.every((order) => rankOf(order.status) >= 4),
    });
}
