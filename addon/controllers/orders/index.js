import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { tracked } from '@glimmer/tracking';
import { action, get } from '@ember/object';
import { inject as service } from '@ember/service';
import { isBlank } from '@ember/utils';
import { isArray } from '@ember/array';
import { timeout, task } from 'ember-concurrency';

export default class OrdersIndexController extends BaseController {
    @service notifications;
    @service intl;
    @service modalsManager;
    @service crud;
    @service fetch;
    @service filters;
    @service hostRouter;
    @service storefront;
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
    @tracked currency = 'USD';

    @tracked queryParams = this.registeredQueryParams('order', [
        'page',
        'limit',
        'sort',
        'query',
        'public_id',
        'internal_id',
        'payload',
        'tracking_number',
        'facilitator',
        'customer',
        'driver',
        'pickup',
        'dropoff',
        'created_by',
        'updated_by',
        'status',
    ]);

    /**
     * Header buttons. Extensions add to them through `storefront:table:order:actions`.
     *
     * @var {Array}
     */
    get actionButtons() {
        return [
            {
                id: 'export',
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                text: this.intl.t('storefront.common.export'),
                permission: 'storefront export order',
            },
        ];
    }

    /**
     * Bulk actions for the selected orders. Extensions add to them through `storefront:table:order:bulk-actions`.
     *
     * @var {Array}
     */
    get bulkActions() {
        return [
            {
                id: 'bulk-cancel',
                label: this.intl.t('storefront.component.widget.orders.cancel-orders'),
                icon: 'ban',
                fn: this.bulkCancelOrders,
                permission: 'fleet-ops cancel order',
            },
            {
                id: 'bulk-delete',
                label: this.intl.t('fleet-ops.operations.orders.index.delete-orders'),
                icon: 'trash',
                class: 'text-red-500',
                fn: this.bulkDeleteOrders,
                permission: 'fleet-ops delete order',
            },
            { separator: true },
            {
                id: 'bulk-dispatch',
                label: this.intl.t('fleet-ops.operations.orders.index.dispatch-orders'),
                icon: 'rocket',
                fn: this.bulkDispatchOrders,
                permission: 'fleet-ops dispatch order',
            },
        ];
    }

    @tracked columns = [
        {
            id: 'public-id',
            sticky: true,
            label: this.intl.t('storefront.common.id'),
            valuePath: 'public_id',
            width: '130px',
            cellComponent: 'table/cell/anchor',
            onClick: this.viewOrder,
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
            hidden: true,
            filterComponent: 'filter/string',
        },
        {
            id: 'customer-name',
            label: this.intl.t('storefront.orders.index.customer'),
            valuePath: 'customer.name',
            cellComponent: 'table/cell/base',
            width: '100px',
            resizable: true,
            sortable: true,
            hidden: false,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: this.intl.t('storefront.orders.index.select-order-customer'),
            filterParam: 'customer',
            model: 'customer',
        },
        {
            id: 'meta-total',
            label: this.intl.t('storefront.orders.index.total'),
            cellComponent: 'table/cell/currency',
            currency: this.currency,
            valuePath: 'meta.total',
            width: '100px',
            resizable: true,
            hidden: false,
            sortable: true,
        },
        {
            id: 'pickup-name',
            label: this.intl.t('storefront.common.pickup'),
            valuePath: 'pickupName',
            cellComponent: 'table/cell/base',
            width: '150px',
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
            cellComponent: 'table/cell/base',
            width: '150px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: this.intl.t('storefront.orders.index.select-order-dropoff-location'),
            filterParam: 'dropoff',
            model: 'place',
        },
        {
            id: 'driver-assigned',
            label: this.intl.t('storefront.orders.index.driver-assigned'),
            cellComponent: 'table/cell/driver-name',
            valuePath: 'driver_assigned',
            modelPath: 'driver_assigned',
            width: '150px',
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
            id: 'scheduled-at',
            label: this.intl.t('storefront.orders.index.scheduled-at'),
            valuePath: 'scheduledAt',
            sortParam: 'scheduled_at',
            filterParam: 'scheduled_at',
            width: '125px',
            resizable: true,
            sortable: true,
            filterable: true,
            hidden: true,
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
            id: 'tracking-number-tracking-number',
            label: this.intl.t('storefront.orders.index.tracking-number'),
            cellComponent: 'table/cell/base',
            valuePath: 'tracking_number.tracking_number',
            width: '160px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
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
            width: '140px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            // filterOptions: this.statusOptions,
        },
        {
            id: 'created-at-short',
            label: this.intl.t('storefront.orders.index.created-at'),
            valuePath: 'createdAtShort',
            sortParam: 'created_at',
            filterParam: 'created_at',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            id: 'updated-at-short',
            label: this.intl.t('storefront.orders.index.updated-at'),
            valuePath: 'updatedAtShort',
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
            sticky: 'right',
            width: 60,
            actions: [
                {
                    id: 'view-order',
                    label: this.intl.t('fleet-ops.operations.orders.index.view-order'),
                    icon: 'eye',
                    fn: this.viewOrder,
                    permission: 'fleet-ops view order',
                },
                {
                    id: 'dispatch-order',
                    label: this.intl.t('fleet-ops.operations.orders.index.dispatch-order'),
                    icon: 'paper-plane',
                    fn: this.dispatchOrder,
                    permission: 'fleet-ops dispatch order',
                    isVisible: (order) => order.canBeDispatched,
                },
                {
                    id: 'cancel-order',
                    label: this.intl.t('storefront.component.widget.orders.cancel-order'),
                    icon: 'ban',
                    fn: this.cancelOrder,
                    permission: 'fleet-ops cancel order',
                },
                {
                    separator: true,
                },
                {
                    id: 'delete-order',
                    label: this.intl.t('fleet-ops.operations.orders.index.delete-order'),
                    icon: 'trash',
                    fn: this.deleteOrder,
                    permission: 'fleet-ops delete order',
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    constructor() {
        super(...arguments);
        this.currency = get(this.storefront, 'activeStore.currency');
    }

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

    @action viewOrder(order) {
        return this.transitionToRoute('orders.index.view', order);
    }

    /**
     * Cancels a specific order after confirmation.
     * @param {Object} order - The order to cancel.
     * @param {Object} [options={}] - Additional options for the modal.
     * @action
     * @memberof OperationsOrdersIndexController
     */
    @action cancelOrder(order, options = {}) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.component.widget.orders.cancel-order-modal-title'),
            body: this.intl.t('storefront.component.widget.orders.cancel-order-modal-body'),
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch('orders/cancel', { order: order.id }, { namespace: 'storefront/int/v1' });
                    order.set('status', 'canceled');
                    this.notifications.success(this.intl.t('storefront.component.widget.orders.cancel-order-success', { orderId: order.public_id }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action dispatchOrder(order, options = {}) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.operations.orders.index.dispatch-title'),
            body: this.intl.t('fleet-ops.operations.orders.index.dispatch-body'),
            acceptButtonScheme: 'primary',
            acceptButtonText: 'Dispatch',
            acceptButtonIcon: 'paper-plane',
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch('orders/dispatch', { order: order.id });
                    order.set('status', 'dispatched');
                    this.notifications.success(this.intl.t('fleet-ops.operations.orders.index.dispatch-success', { orderId: order.public_id }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action deleteOrder(order, options = {}) {
        this.crud.delete(order, {
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
            ...options,
        });
    }

    @action bulkDeleteOrders(selected = []) {
        selected = selected.length > 0 ? selected : this.table.selectedRows;

        this.crud.bulkDelete(selected, {
            modelNamePath: `public_id`,
            acceptButtonText: 'Delete Orders',
            onSuccess: async () => {
                await this.hostRouter.refresh();
                this.table.untoggleSelectAll();
            },
        });
    }

    @action bulkCancelOrders(selected = []) {
        selected = selected.length > 0 ? selected : this.table.selectedRows;

        if (!isArray(selected) || selected.length === 0) {
            return;
        }

        this.crud.bulkAction('cancel', selected, {
            acceptButtonText: this.intl.t('storefront.component.widget.orders.cancel-orders'),
            acceptButtonScheme: 'danger',
            acceptButtonIcon: 'ban',
            modelNamePath: `public_id`,
            actionPath: `orders/bulk-cancel`,
            actionMethod: `PATCH`,
            onConfirm: (canceledOrders) => {
                canceledOrders.forEach((order) => {
                    order.set('status', 'canceled');
                });
            },
            onSuccess: async () => {
                await this.hostRouter.refresh();
                this.table.untoggleSelectAll();
            },
        });
    }

    @action bulkDispatchOrders(selected = []) {
        selected = selected.length > 0 ? selected : this.table.selectedRows;

        if (!isArray(selected) || selected.length === 0) {
            return;
        }

        this.crud.bulkAction('dispatch', selected, {
            acceptButtonText: 'Dispatch Orders',
            acceptButtonScheme: 'magic',
            acceptButtonIcon: 'rocket',
            modelNamePath: 'public_id',
            actionPath: 'orders/bulk-dispatch',
            actionMethod: 'POST',
            onConfirm: (dispatchedOrders) => {
                dispatchedOrders.forEach((order) => {
                    order.set('status', 'dispatched');
                });
            },
            onSuccess: async () => {
                await this.hostRouter.refresh();
                this.table.untoggleSelectAll();
            },
        });
    }
}
