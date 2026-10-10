import Component from '@glimmer/component';
import { orderStoreId, orderStoreName } from '../../../../../utils/order-groups';

const MAX_VISIBLE = 4;

/**
 * The stores behind a row: one for an order, up to four tiles plus an overflow count for a checkout group.
 */
export default class StorefrontNetworkOrdersCellStoresComponent extends Component {
    get entries() {
        const { row, column } = this.args;
        const storesById = column?.storesById ?? {};
        const ids = row?.isGroup ? (row.storeIds ?? []) : [orderStoreId(row)].filter(Boolean);
        const names = row?.isGroup ? (row.storeNames ?? []) : [orderStoreName(row)].filter(Boolean);

        return ids.map((id, index) => ({ id, store: storesById[id] ?? null, name: storesById[id]?.name ?? names[index] ?? names[0] ?? id }));
    }

    get visible() {
        return this.entries.slice(0, MAX_VISIBLE);
    }

    get overflow() {
        return Math.max(0, this.entries.length - MAX_VISIBLE);
    }

    get overflowTitle() {
        return this.entries
            .slice(MAX_VISIBLE)
            .map((entry) => entry.name)
            .join(', ');
    }
}
