import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debounce } from '@ember/runloop';

export default class PromotionsIndexController extends Controller {
    @service store;
    @service intl;
    @service storefront;
    @service modalsManager;
    @service notifications;
    @service crud;
    @service fetch;
    @service hostRouter;
    queryParams = ['query', 'status', 'page'];

    @tracked query;
    @tracked status;
    @tracked page = 1;

    get columns() {
        return [
            {
                label: this.intl.t('storefront.promotions.common.name'),
                valuePath: 'name',
                width: '22%',
                cellComponent: 'table/cell/anchor',
                action: this.editPromotion,
                resizable: true,
            },
            {
                label: this.intl.t('storefront.promotions.list.type'),
                valuePath: 'type',
                width: '14%',
                cellComponent: 'storefront/promotion/type-cell',
                resizable: true,
            },
            {
                label: this.intl.t('storefront.promotions.list.trigger'),
                valuePath: 'trigger',
                width: '10%',
                cellComponent: 'storefront/promotion/trigger-cell',
                resizable: true,
            },
            {
                label: this.intl.t('storefront.promotions.common.status'),
                valuePath: 'status',
                width: '10%',
                cellComponent: 'table/cell/status',
                resizable: true,
            },
            {
                label: this.intl.t('storefront.promotions.list.runs'),
                valuePath: 'runsFrom',
                width: '18%',
                cellComponent: 'storefront/promotion/runs-cell',
                resizable: true,
            },
            {
                label: this.intl.t('storefront.promotions.list.redemptions'),
                valuePath: 'redemptionsCount',
                width: '10%',
                resizable: true,
            },
            {
                label: this.intl.t('storefront.promotions.list.discount-given'),
                valuePath: 'discountGiven',
                width: '12%',
                cellComponent: 'table/cell/currency',
                currencyPath: 'currency',
                resizable: true,
            },
            {
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
                    { label: this.intl.t('storefront.promotions.common.edit'), fn: this.editPromotion, permission: 'storefront update promotion' },
                    { label: this.intl.t('storefront.promotions.list.manage-codes'), fn: this.manageCodes, permission: 'storefront generate-codes promotion' },
                    { label: this.intl.t('storefront.promotions.list.announce'), fn: this.announce, permission: 'storefront announce promotion' },
                    { separator: true },
                    { label: this.intl.t('storefront.promotions.list.activate'), fn: (promotion) => this.setStatus(promotion, 'active'), permission: 'storefront update promotion' },
                    { label: this.intl.t('storefront.promotions.list.pause'), fn: (promotion) => this.setStatus(promotion, 'paused'), permission: 'storefront update promotion' },
                    { label: this.intl.t('storefront.promotions.list.end'), fn: (promotion) => this.setStatus(promotion, 'ended'), permission: 'storefront update promotion' },
                    { separator: true },
                    {
                        label: this.intl.t('storefront.promotions.common.delete'),
                        fn: this.deletePromotion,
                        class: 'text-red-700 hover:text-red-800',
                        permission: 'storefront delete promotion',
                    },
                ],
            },
        ];
    }

    @action search({ target }) {
        debounce(this, this.setQuery, target.value, 300);
    }

    setQuery(query) {
        this.query = query;
        this.page = 1;
    }

    @action resetFilters() {
        this.query = undefined;
        this.status = undefined;
        this.page = 1;
    }

    @action createPromotion() {
        const activeStore = this.storefront.activeStore;
        const promotion = this.store.createRecord('promotion', {
            owner_uuid: activeStore.id,
            owner_type: 'storefront:store',
            currency: activeStore.currency,
            applies_to: {},
            bogo_config: { buy_quantity: 1, get_quantity: 1, discount_percent: 100 },
        });

        return this.editPromotion(promotion, {
            title: this.intl.t('storefront.promotions.list.new-promotion'),
            successMessage: this.intl.t('storefront.promotions.list.created-success'),
            decline: (modal) => {
                promotion.rollbackAttributes();
                modal.done();
            },
        });
    }

    @action editPromotion(promotion, options = {}) {
        this.modalsManager.show('modals/promotion-form', {
            title: this.intl.t('storefront.promotions.list.edit-promotion'),
            acceptButtonText: this.intl.t('storefront.promotions.common.save'),
            acceptButtonIcon: 'save',
            modalClass: 'modal-lg',
            promotion,
            currency: promotion.currency ?? this.storefront.activeStore?.currency,
            storeId: this.storefront.activeStore?.id,
            decline: (modal) => {
                promotion.rollbackAttributes();
                modal.done();
            },
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await promotion.save();
                    this.notifications.success(options.successMessage ?? this.intl.t('storefront.promotions.list.saved-success'));
                    this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
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
}
