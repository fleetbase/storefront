import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { relationValue } from '@fleetbase/ember-ui/utils/resource-registry';
import { buildIdentityStub } from '@fleetbase/fleetops-data/utils/identity-stub';
import { inject as service } from '@ember/service';
import { tracked, cached } from '@glimmer/tracking';
import { isBlank } from '@ember/utils';
import { timeout, task } from 'ember-concurrency';
import { action } from '@ember/object';
import { groupOrdersByCheckout, STATUS_TABS } from '../../../../utils/order-groups';

/**
 * Everything placed through the network, grouped by checkout: a multi-store purchase is one
 * row that expands into the order each store fulfils.
 */
export default class NetworksIndexNetworkOrdersController extends BaseController {
    @service store;
    @service notifications;
    @service intl;
    @service modalsManager;
    @service crud;
    @service fetch;
    @service filters;
    @service hostRouter;
    @service storefrontOrderActions;

    queryParams = this.registeredQueryParams('network-order', ['view']);

    @tracked page = 1;
    @tracked limit;
    @tracked query;
    @tracked sort = '-created_at';
    @tracked status;
    @tracked view = 'grouped';
    @tracked network;
    @tracked stores = [];
    @tracked table;

    get isGrouped() {
        return this.view !== 'flat';
    }

    get activeStatusTab() {
        const current = String(this.status ?? '')
            .split(',')
            .filter(Boolean)
            .sort()
            .join(',');

        return STATUS_TABS.find((tab) => tab.statuses.slice().sort().join(',') === current)?.id ?? 'custom';
    }

    get statusTabs() {
        return STATUS_TABS.map((tab) => ({
            ...tab,
            label: this.intl.t(`storefront.networks.orders.tabs.${tab.id}`),
            isActive: tab.id === this.activeStatusTab,
        }));
    }

    get storesById() {
        return this.stores.reduce((map, store) => {
            map[store.public_id] = store;
            map[store.id] = store;
            return map;
        }, {});
    }

    /**
     * Rows for the table: checkout groups in grouped view, orders otherwise.
     */
    get rows() {
        return this.isGrouped ? groupOrdersByCheckout(this.model) : (this.model?.toArray?.() ?? Array.from(this.model ?? []));
    }

    @cached get columns() {
        return [
            {
                id: 'reference',
                label: this.intl.t('storefront.networks.orders.columns.reference'),
                valuePath: 'public_id',
                cellComponent: 'storefront/network/orders/cell/reference',
                onView: this.viewOrder,
                width: '190px',
                resizable: true,
                sortable: false,
            },
            {
                id: 'customer',
                label: this.intl.t('storefront.orders.index.customer'),
                valuePath: 'customer.name',
                cellComponent: 'table/cell/identity',
                resourceType: 'customer',
                resourcePath: (row) => relationValue(row, 'customer') ?? buildIdentityStub(row, { type: row.customer_type ?? 'customer', nameKey: 'customer_name' }),
                action: this.viewCustomer,
                emptyText: this.intl.t('storefront.networks.orders.no-customer'),
                width: '170px',
                resizable: true,
            },
            {
                id: 'stores',
                label: this.intl.t('storefront.networks.index.network.stores.store'),
                cellComponent: 'storefront/network/orders/cell/stores',
                storesById: this.storesById,
                width: '200px',
                resizable: true,
            },
            {
                id: 'fulfillment',
                label: this.intl.t('storefront.networks.orders.columns.fulfillment'),
                cellComponent: 'storefront/network/orders/cell/fulfillment',
                width: '110px',
            },
            {
                id: 'total',
                label: this.intl.t('storefront.networks.orders.columns.total'),
                cellComponent: 'storefront/network/orders/cell/total',
                width: '140px',
                resizable: true,
            },
            {
                id: 'driver',
                label: this.intl.t('storefront.orders.index.driver-assigned'),
                cellComponent: 'storefront/network/orders/cell/driver',
                onAssign: this.assignDriver,
                width: '170px',
                resizable: true,
            },
            {
                id: 'status',
                label: this.intl.t('storefront.common.status'),
                cellComponent: 'storefront/network/orders/cell/status',
                width: '150px',
                resizable: true,
            },
            {
                id: 'placed',
                label: this.intl.t('storefront.networks.orders.columns.placed'),
                cellComponent: 'storefront/network/orders/cell/placed',
                width: '110px',
            },
            {
                id: 'row-actions',
                label: '',
                cellComponent: 'storefront/network/orders/cell/actions',
                onView: this.viewOrder,
                onCancel: this.cancelOrder,
                onDelete: this.deleteOrder,
                onChange: this.refresh,
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                width: '170px',
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    /**
     * The search task.
     *
     * @void
     */
    @task({ restartable: true }) *search({ target: { value } }) {
        if (isBlank(value)) {
            this.query = null;
            return;
        }

        yield timeout(250);

        if (this.page > 1) {
            this.page = 1;
        }

        this.query = value;
    }

    @action selectStatusTab(tab) {
        this.status = tab.statuses?.length ? tab.statuses.join(',') : null;
        this.page = 1;
    }

    @action setView(view) {
        this.view = view;
    }

    @action refresh() {
        return this.hostRouter.refresh();
    }

    /**
     * Opens the storefront customer behind an order. A stub that only carries
     * the customer's name has no page to open.
     * @param {Object} customer
     */
    @action viewCustomer(customer) {
        if (!customer?.public_id) {
            return;
        }

        return this.transitionToRoute('customers.index.view', customer.public_id);
    }

    @action viewOrder(order) {
        return this.storefrontOrderActions.viewOrder(order, { onChange: this.refresh });
    }

    @action assignDriver(order) {
        return this.storefrontOrderActions.assignDriver(order, this.refresh);
    }

    @action cancelOrder(order) {
        return this.storefrontOrderActions.cancelOrder(order, this.refresh);
    }

    @action deleteOrder(order) {
        return this.crud.delete(order, {
            onSuccess: () => this.refresh(),
        });
    }

    /**
     * The columns with what extensions registered under `storefront:network-order:table` merged in.
     *
     * @var {Array}
     */
    @cached get registeredColumns() {
        return this.mergeRegisteredColumns('network-order', this.columns);
    }

    /**
     * Toolbar buttons extensions registered under `storefront:network-order:table:actions`.
     *
     * @var {Array}
     */
    get registeredActionButtons() {
        return this.registeredTableActions('network-order');
    }
}
