import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debounce } from '@ember/runloop';

export default class PromotionsSegmentsController extends Controller {
    @service store;
    @service intl;
    @service storefront;
    @service modalsManager;
    @service notifications;
    @service crud;
    @service hostRouter;
    queryParams = ['query', 'page'];

    @tracked query;
    @tracked page = 1;

    get columns() {
        return [
            {
                label: this.intl.t('storefront.promotions.common.name'),
                valuePath: 'name',
                width: '30%',
                cellComponent: 'table/cell/anchor',
                action: this.editSegment,
                resizable: true,
            },
            {
                label: this.intl.t('storefront.promotions.common.description'),
                valuePath: 'description',
                width: '40%',
                resizable: true,
            },
            {
                label: this.intl.t('storefront.promotions.segments.rules'),
                valuePath: 'ruleCount',
                width: '15%',
                resizable: true,
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    { label: this.intl.t('storefront.promotions.common.edit'), fn: this.editSegment, permission: 'storefront update customer-segment' },
                    {
                        label: this.intl.t('storefront.promotions.common.delete'),
                        fn: this.deleteSegment,
                        class: 'text-red-700 hover:text-red-800',
                        permission: 'storefront delete customer-segment',
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
        this.page = 1;
    }

    @action createSegment() {
        const segment = this.store.createRecord('customer-segment', {
            owner_uuid: this.storefront.activeStore.id,
            owner_type: 'storefront:store',
            rules: {},
        });

        return this.editSegment(segment, {
            title: this.intl.t('storefront.promotions.segments.new-segment'),
            decline: (modal) => {
                segment.rollbackAttributes();
                modal.done();
            },
        });
    }

    @action editSegment(segment, options = {}) {
        this.modalsManager.show('modals/customer-segment-form', {
            title: this.intl.t('storefront.promotions.segments.edit-segment'),
            acceptButtonText: this.intl.t('storefront.promotions.common.save'),
            acceptButtonIcon: 'save',
            modalClass: 'modal-lg',
            segment,
            currency: this.storefront.activeStore?.currency,
            decline: (modal) => {
                segment.rollbackAttributes();
                modal.done();
            },
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await segment.save();
                    this.notifications.success(this.intl.t('storefront.promotions.segments.saved-success'));
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

    @action deleteSegment(segment) {
        this.crud.delete(segment, {
            onSuccess: () => this.hostRouter.refresh(),
        });
    }
}
