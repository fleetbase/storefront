import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { relationValue } from '@fleetbase/ember-ui/utils/resource-registry';
import { buildIdentityStub } from '@fleetbase/fleetops-data/utils/identity-stub';
import { inject as service } from '@ember/service';
import { tracked, cached } from '@glimmer/tracking';
import { isBlank } from '@ember/utils';
import { timeout, task } from 'ember-concurrency';
import { action } from '@ember/object';

export default class NetworksIndexNetworkOrdersController extends BaseController {
    /**
     * Inject the `notifications` service
     *
     * @var {Service}
     */
    @service store;
    @service notifications;

    /**
     * Inject the `intl` service
     *
     * @var {Service}
     */
    @service intl;

    /**
     * Inject the `modals-manager` service
     *
     * @var {Service}
     */
    @service modalsManager;

    /**
     * Inject the `crud` service
     *
     * @var {Service}
     */
    @service crud;

    /**
     * Inject the `fetch` service
     *
     * @var {Service}
     */
    @service fetch;

    /**
     * Inject the `filters` service
     *
     * @var {Service}
     */
    @service filters;

    @service storefrontOrderActions;

    /**
     * Queryable parameters for this controller's model
     *
     * @var {Array}
     */
    queryParams = this.registeredQueryParams('network-order', []);

    @tracked page = 1;
    @tracked limit;
    @tracked query;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked internal_id;
    @tracked tracking;
    @tracked facilitator;
    @tracked customer;
    @tracked driver;
    @tracked payload;
    @tracked pickup;
    @tracked dropoff;
    @tracked updated_by;
    @tracked created_by;
    @tracked status;

    @tracked columns = [
        {
            id: 'public-id',
            label: this.intl.t('storefront.common.id'),
            valuePath: 'public_id',
            width: '150px',
            cellComponent: 'table/cell/anchor',
            action: this.viewOrder,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            id: 'internal-id',
            label: this.intl.t('storefront.orders.index.internal-id'),
            valuePath: 'internal_id',
            width: '125px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            id: 'customer-name',
            label: this.intl.t('storefront.orders.index.customer'),
            valuePath: 'customer.name',
            cellComponent: 'table/cell/identity',
            resourceType: 'customer',
            resourcePath: (order) => relationValue(order, 'customer') ?? buildIdentityStub(order, { type: order.customer_type ?? 'customer', nameKey: 'customer_name' }),
            action: this.viewCustomer,
            emptyText: 'No customer',
            width: '125px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: this.intl.t('storefront.orders.index.select-order-customer'),
            filterParam: 'customer',
            model: 'customer',
        },
        {
            id: 'pickup-name',
            label: this.intl.t('storefront.common.pickup'),
            valuePath: 'pickupName',
            cellComponent: 'table/cell/identity',
            resourceType: 'place',
            resourcePath: (order) => relationValue(relationValue(order, 'payload'), 'pickup') ?? buildIdentityStub(order, { type: 'place', nameKey: 'pickupName' }),
            width: '160px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: this.intl.t('storefront.orders.index.select-order-pickup-location'),
            filterParam: 'pickup',
            model: 'place',
        },
        {
            id: 'dropoff-name',
            label: this.intl.t('storefront.common.dropoff'),
            valuePath: 'dropoffName',
            cellComponent: 'table/cell/identity',
            resourceType: 'place',
            resourcePath: (order) => relationValue(relationValue(order, 'payload'), 'dropoff') ?? buildIdentityStub(order, { type: 'place', nameKey: 'dropoffName' }),
            width: '160px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: this.intl.t('storefront.orders.index.select-order-dropoff-location'),
            filterParam: 'dropoff',
            model: 'place',
        },
        {
            id: 'scheduled-at',
            label: this.intl.t('storefront.orders.index.scheduled-at'),
            valuePath: 'scheduledAt',
            sortParam: 'scheduled_at',
            filterParam: 'scheduled_at',
            width: '150px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            id: 'item-count',
            label: '# Items',
            cellComponent: 'table/cell/base',
            valuePath: 'item_count',
            resizable: true,
            hidden: true,
            width: '50px',
        },
        {
            id: 'transaction-amount',
            label: this.intl.t('storefront.orders.index.transaction-total'),
            cellComponent: 'table/cell/base',
            valuePath: 'transaction_amount',
            width: '50px',
            resizable: true,
            hidden: true,
            sortable: true,
        },
        {
            id: 'tracking-number-tracking-number',
            label: this.intl.t('storefront.orders.index.tracking-number'),
            cellComponent: 'table/cell/base',
            valuePath: 'tracking_number.tracking_number',
            width: '170px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            id: 'driver-assigned',
            label: this.intl.t('storefront.orders.index.driver-assigned'),
            cellComponent: 'table/cell/identity',
            resourceType: 'driver',
            valuePath: 'driver_assigned',
            resourcePath: (order) =>
                relationValue(order, 'driver_assigned') ??
                buildIdentityStub(order, { type: 'driver', load: () => (order.driver_assigned_uuid ? this.store.findRecord('driver', order.driver_assigned_uuid) : null) }),
            emptyText: 'No driver assigned',
            width: '170px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: this.intl.t('storefront.orders.index.select-driver-for-order'),
            filterParam: 'driver',
            model: 'driver',
            query: {
                // no model, serializer, adapter for relations
                without: ['fleets', 'vendor', 'vehicle', 'currentJob'],
            },
        },
        {
            id: 'type',
            label: this.intl.t('storefront.common.type'),
            cellComponent: 'cell/humanize',
            valuePath: 'type',
            width: '100px',
            resizable: true,
            hidden: true,
            sortable: true,
        },
        {
            id: 'status',
            label: this.intl.t('storefront.common.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            // filterOptions: this.statusOptions,
        },
        {
            id: 'created-at',
            label: this.intl.t('storefront.orders.index.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            filterParam: 'created_at',
            width: '140px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            id: 'updated-at',
            label: this.intl.t('storefront.orders.index.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            filterParam: 'updated_at',
            width: '125px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            id: 'created-by-name',
            label: this.intl.t('storefront.orders.index.created-by'),
            valuePath: 'created_by_name',
            width: '125px',
            resizable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select user',
            filterParam: 'created_by',
            model: 'user',
        },
        {
            id: 'updated-by-name',
            label: this.intl.t('storefront.orders.index.updated-by'),
            valuePath: 'updated_by_name',
            width: '125px',
            resizable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: this.intl.t('storefront.orders.index.select-user'),
            filterParam: 'updated_by',
            model: 'user',
        },
        {
            id: 'row-actions',
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Order Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '12%',
            actions: [
                {
                    id: 'view-order',
                    label: this.intl.t('storefront.orders.index.view-order'),
                    icon: 'eye',
                    fn: this.viewOrder,
                },
                {
                    id: 'cancel-order',
                    label: this.intl.t('storefront.orders.index.cancel-order'),
                    icon: 'ban',
                    fn: this.cancelOrder,
                },
                {
                    separator: true,
                },
                {
                    id: 'delete-order',
                    label: this.intl.t('storefront.orders.index.delete-order'),
                    icon: 'trash',
                    fn: this.deleteOrder,
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    /**
     * The search task.
     *
     * @void
     */
    @task({ restartable: true }) *search({ target: { value } }) {
        // if no query don't search
        if (isBlank(value)) {
            this.query = null;
            return;
        }

        // timeout for typing
        yield timeout(250);

        // reset page for results
        if (this.page > 1) {
            this.page = 1;
        }

        // update the query param
        this.query = value;
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
        return this.storefrontOrderActions.viewOrder(order);
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
