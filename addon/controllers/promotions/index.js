import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { tracked, cached } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { getOwner } from '@ember/application';
import { debounce } from '@ember/runloop';
import promotionLifecycle from '@fleetbase/storefront-engine/utils/promotion-lifecycle';

export default class PromotionsIndexController extends BaseController {
    @service store;
    @service intl;
    @service storefront;
    @service modalsManager;
    @service notifications;
    @service crud;
    @service fetch;
    @service hostRouter;
    queryParams = this.registeredQueryParams('promotion', ['query', 'status', 'page', 'view']);

    @tracked query;
    @tracked status;
    @tracked page = 1;
    @tracked view = 'cards';
    @tracked builderPromotion = null;

    statusTabs = ['all', 'active', 'scheduled', 'paused', 'ended', 'draft'];

    get activeStatusTab() {
        return this.status ?? 'all';
    }

    /** Which tab a promotion falls under; scheduled is an active promotion that has not started. */
    tabOf(promotion) {
        return promotionLifecycle(promotion);
    }

    get allRows() {
        return this.model?.toArray?.() ?? Array.from(this.model ?? []);
    }

    get hasAny() {
        return this.allRows.length > 0;
    }

    get tabs() {
        const counts = this.allRows.reduce((map, promotion) => {
            const tab = this.tabOf(promotion);
            map[tab] = (map[tab] ?? 0) + 1;
            return map;
        }, {});

        return this.statusTabs.map((id) => ({
            id,
            label: this.intl.t(`storefront.promotions.statuses.${id}`),
            count: id === 'all' ? this.allRows.length : (counts[id] ?? 0),
            isActive: id === this.activeStatusTab,
        }));
    }

    get activeTabLabel() {
        return this.intl.t(`storefront.promotions.statuses.${this.activeStatusTab}`).toLowerCase();
    }

    get isCards() {
        return this.view !== 'table';
    }

    /**
     * The promotions on the selected tab. The hub loads the owner's promotions once and the
     * tabs filter them here, so switching tabs is instant and never empties the page.
     */
    get rows() {
        const tab = this.activeStatusTab;

        return tab === 'all' ? this.allRows : this.allRows.filter((promotion) => this.tabOf(promotion) === tab);
    }

    get cardActions() {
        return this.columns.find((column) => column.id === 'row-actions')?.actions ?? [];
    }

    get columns() {
        return [
            {
                id: 'name',
                label: this.intl.t('storefront.promotions.common.name'),
                valuePath: 'name',
                width: '22%',
                cellComponent: 'table/cell/anchor',
                action: this.editPromotion,
                resizable: true,
            },
            {
                id: 'type',
                label: this.intl.t('storefront.promotions.list.type'),
                valuePath: 'type',
                width: '14%',
                cellComponent: 'storefront/promotion/type-cell',
                resizable: true,
            },
            {
                id: 'trigger',
                label: this.intl.t('storefront.promotions.list.trigger'),
                valuePath: 'trigger',
                width: '10%',
                cellComponent: 'storefront/promotion/trigger-cell',
                resizable: true,
            },
            {
                id: 'status',
                label: this.intl.t('storefront.promotions.common.status'),
                valuePath: 'lifecycleStatus',
                width: '10%',
                cellComponent: 'table/cell/status',
                resizable: true,
            },
            {
                id: 'runs-from',
                label: this.intl.t('storefront.promotions.list.runs'),
                valuePath: 'runsFrom',
                width: '18%',
                cellComponent: 'storefront/promotion/runs-cell',
                resizable: true,
            },
            {
                id: 'redemptions-count',
                label: this.intl.t('storefront.promotions.list.redemptions'),
                valuePath: 'redemptionsCount',
                width: '10%',
                resizable: true,
            },
            {
                id: 'discount-given',
                label: this.intl.t('storefront.promotions.list.discount-given'),
                valuePath: 'discountGiven',
                width: '12%',
                cellComponent: 'table/cell/currency',
                currencyPath: 'currency',
                resizable: true,
            },
            {
                id: 'row-actions',
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('storefront.promotions.list.actions'),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    { id: 'edit-promotion', label: this.intl.t('storefront.promotions.common.edit'), fn: this.editPromotion, permission: 'storefront update promotion' },
                    { id: 'manage-codes', label: this.intl.t('storefront.promotions.list.manage-codes'), fn: this.manageCodes, permission: 'storefront generate-codes promotion' },
                    { id: 'duplicate', label: this.intl.t('storefront.promotions.list.duplicate'), fn: this.duplicatePromotion, permission: 'storefront create promotion' },
                    { id: 'view-redemptions', label: this.intl.t('storefront.promotions.list.view-redemptions'), fn: this.viewRedemptions, permission: 'storefront list promotion' },
                    { id: 'announce', label: this.intl.t('storefront.promotions.list.announce'), fn: this.announce, permission: 'storefront announce promotion' },
                    { separator: true },
                    {
                        id: 'activate',
                        label: this.intl.t('storefront.promotions.list.activate'),
                        fn: (promotion) => this.setStatus(promotion, 'active'),
                        permission: 'storefront update promotion',
                    },
                    {
                        id: 'pause',
                        label: this.intl.t('storefront.promotions.list.pause'),
                        fn: (promotion) => this.setStatus(promotion, 'paused'),
                        permission: 'storefront update promotion',
                    },
                    {
                        id: 'end',
                        label: this.intl.t('storefront.promotions.list.end'),
                        fn: (promotion) => this.setStatus(promotion, 'ended'),
                        permission: 'storefront update promotion',
                    },
                    { separator: true },
                    {
                        id: 'delete-promotion',
                        label: this.intl.t('storefront.promotions.common.delete'),
                        fn: this.deletePromotion,
                        class: 'text-red-700 hover:text-red-800',
                        permission: 'storefront delete promotion',
                    },
                ],
            },
        ];
    }

    @tracked table;

    get selectedPromotions() {
        const table = this.table;

        if (!table || table.isDestroying || table.isDestroyed) {
            return [];
        }

        return table.selectedRows ?? [];
    }

    get hasSelection() {
        return this.selectedPromotions.length > 0;
    }

    @action clearSelection() {
        this.table?.selectedRows?.forEach?.((row) => this.table.deselectRow?.(row));
        this.table?.clearSelectedRows?.();
    }

    /**
     * Bulk pause, resume or end: the same status change as the row action, on every selected row.
     */
    @action async bulkSetStatus(status) {
        const promotions = [...this.selectedPromotions];

        if (!promotions.length) {
            return;
        }

        try {
            await Promise.all(
                promotions.map((promotion) => {
                    promotion.status = status;

                    return promotion.save();
                })
            );
            this.notifications.success(this.intl.t('storefront.promotions.list.bulk-updated', { count: promotions.length }));
            this.clearSelection();
            this.hostRouter.refresh();
        } catch (error) {
            promotions.forEach((promotion) => promotion.rollbackAttributes());
            this.notifications.serverError(error);
        }
    }

    @action search({ target }) {
        debounce(this, this.setQuery, target.value, 300);
    }

    setQuery(query) {
        this.query = query;
        this.page = 1;
    }

    @action selectStatusTab(tab) {
        const id = tab.id ?? tab;
        this.status = id === 'all' ? null : id;
    }

    @action setView(view) {
        this.view = view;
    }

    @action duplicatePromotion(promotion) {
        const copy = this.store.createRecord('promotion', {
            owner_uuid: promotion.owner_uuid,
            owner_type: promotion.owner_type,
            currency: promotion.currency,
            name: `${promotion.name} (copy)`,
            description: promotion.description,
            type: promotion.type,
            trigger: promotion.trigger,
            value: promotion.value,
            max_discount_amount: promotion.max_discount_amount,
            min_subtotal: promotion.min_subtotal,
            min_items: promotion.min_items,
            applies_to: { ...(promotion.applies_to ?? {}) },
            bogo_config: { ...(promotion.bogo_config ?? {}) },
            first_order_only: promotion.first_order_only,
            usage_limit: promotion.usage_limit,
            usage_limit_per_customer: promotion.usage_limit_per_customer,
            budget_amount: promotion.budget_amount,
            stackable: promotion.stackable,
            priority: promotion.priority,
            is_public: promotion.is_public,
            status: 'draft',
        });

        this.builderPromotion = copy;
    }

    get ownerId() {
        return this.storefront.getContextOwner('id');
    }

    get ownerType() {
        return this.storefront.contextOwnerType;
    }

    get ownerCurrency() {
        return this.storefront.getContextOwner('currency');
    }

    get isNetworkOwner() {
        return this.storefront.isNetworkContext;
    }

    @action viewRedemptions(promotion) {
        return getOwner(this)
            .lookup('controller:promotions')
            .transitionToHub('promotions.redemptions', { queryParams: { promotion: promotion.id } });
    }

    @action closeBuilder() {
        const promotion = this.builderPromotion;

        if (promotion?.isNew) {
            promotion.rollbackAttributes();
        } else if (promotion?.hasDirtyAttributes) {
            promotion.rollbackAttributes();
        }

        this.builderPromotion = null;
    }

    @action onBuilderSaved(promotion, { announce } = {}) {
        this.hostRouter.refresh();

        if (announce) {
            this.announce(promotion);
        }
    }

    @action resetFilters() {
        this.query = undefined;
        this.status = undefined;
        this.page = 1;
    }

    @action createPromotion() {
        this.builderPromotion = this.store.createRecord('promotion', {
            owner_uuid: this.ownerId,
            owner_type: this.ownerType,
            currency: this.ownerCurrency,
            applies_to: {},
            bogo_config: { buy_quantity: 1, get_quantity: 1, discount_percent: 100 },
        });
    }

    @action editPromotion(promotion) {
        this.builderPromotion = promotion;
    }

    @action async setStatus(promotion, status) {
        promotion.status = status;

        try {
            await promotion.save();
            this.notifications.success(this.intl.t('storefront.promotions.list.status-updated'));
        } catch (error) {
            promotion.rollbackAttributes();
            this.notifications.serverError(error);
        }
    }

    @action manageCodes(promotion) {
        this.modalsManager.show('modals/promotion-codes', {
            title: this.intl.t('storefront.promotions.codes.title', { name: promotion.name }),
            hideAcceptButton: true,
            declineButtonText: this.intl.t('storefront.promotions.common.done'),
            modalClass: 'modal-lg',
            promotion,
        });
    }

    @action announce(promotion) {
        this.modalsManager.show('modals/promotion-announce', {
            title: this.intl.t('storefront.promotions.announce.title', { name: promotion.name }),
            acceptButtonText: this.intl.t('storefront.promotions.list.announce'),
            acceptButtonIcon: 'bullhorn',
            promotion,
            announcement: {
                title: promotion.name,
                body: promotion.description,
                segment: null,
                channels: ['push', 'inbox'],
                send_at: null,
            },
            confirm: async (modal) => {
                const { announcement } = modal.getOptions(['announcement']);
                modal.startLoading();

                try {
                    await this.fetch.post(
                        `promotions/${promotion.id}/announce`,
                        {
                            title: announcement.title,
                            body: announcement.body,
                            segment: announcement.segment?.id,
                            channels: announcement.channels,
                            send_at: announcement.send_at,
                        },
                        { namespace: 'storefront/int/v1' }
                    );
                    this.notifications.success(this.intl.t('storefront.promotions.announce.success'));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action deletePromotion(promotion) {
        this.crud.delete(promotion, {
            onSuccess: () => this.hostRouter.refresh(),
        });
    }

    /**
     * The columns with what extensions registered under `storefront:promotion:table` merged in.
     *
     * @var {Array}
     */
    @cached get registeredColumns() {
        return this.mergeRegisteredColumns('promotion', this.columns);
    }

    /**
     * Toolbar buttons extensions registered under `storefront:promotion:table:actions`.
     *
     * @var {Array}
     */
    get registeredActionButtons() {
        return this.registeredTableActions('promotion');
    }
}
