import Component from '@glimmer/component';
import { orderStoreId, orderStoreName } from '../../../../../utils/order-groups';

const MAX_VISIBLE = 4;

/**
 * The stores behind a row: one pill for an order, up to four round logos plus an overflow count for a checkout group.
 */
export default class StorefrontNetworkOrdersCellStoresComponent extends Component {
    get entries() {
        const { row, column } = this.args;
        const storesById = column?.storesById ?? {};
        const ids = row?.isGroup ? (row.storeIds ?? []) : [orderStoreId(row)].filter(Boolean);
        const names = row?.isGroup ? (row.storeNames ?? []) : [orderStoreName(row)].filter(Boolean);

        return ids.map((id, index) => {
            const name = storesById[id]?.name ?? names[index] ?? names[0] ?? id;

            const store = storesById[id] ?? null;

            return {
                id,
                store,
                name,
                // An order placed on the network itself carries the network's id.
                type: String(store?.public_id ?? id).startsWith('network_') ? 'network' : 'store',
                logo: store?.logo_url ?? null,
                initial: String(name ?? '?')
                    .charAt(0)
                    .toUpperCase(),
            };
        });
    }

    /** More than one store: round logos only, so the row keeps a single line. */
    get isStack() {
        return this.entries.length > 1;
    }

    /** The one store behind a single order. */
    get single() {
        return this.isStack ? null : (this.entries[0] ?? null);
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
