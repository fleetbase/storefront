import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { tracked, cached } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debounce } from '@ember/runloop';

export default class PromotionsCampaignsController extends BaseController {
    @service store;
    @service intl;
    @service storefront;
    @service modalsManager;
    @service notifications;
    @service crud;
    @service fetch;
    @service hostRouter;
    queryParams = this.registeredQueryParams('campaign', ['query', 'status', 'page']);

    @tracked query;
    @tracked status;
    @tracked page = 1;

    get columns() {
        return [
            {
                id: 'name',
                label: this.intl.t('storefront.promotions.common.name'),
                valuePath: 'name',
                width: '22%',
                cellComponent: 'table/cell/anchor',
                action: this.editCampaign,
                resizable: true,
            },
            {
                id: 'status',
                label: this.intl.t('storefront.promotions.common.status'),
                valuePath: 'status',
                width: '10%',
                cellComponent: 'table/cell/status',
                resizable: true,
            },
            {
                id: 'segment-name',
                label: this.intl.t('storefront.promotions.campaigns.audience'),
                valuePath: 'segment_name',
                width: '16%',
                resizable: true,
            },
            {
                id: 'title',
                label: this.intl.t('storefront.promotions.campaigns.title-label'),
                valuePath: 'title',
                width: '20%',
                resizable: true,
            },
            {
                id: 'send-at-formatted',
                label: this.intl.t('storefront.promotions.campaigns.send-at'),
                valuePath: 'sendAtFormatted',
                width: '14%',
                resizable: true,
            },
            {
                id: 'targeted',
                label: this.intl.t('storefront.promotions.campaigns.targeted'),
                valuePath: 'targeted',
                width: '10%',
                resizable: true,
            },
            {
                id: 'row-actions',
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
                    { id: 'edit-campaign', label: this.intl.t('storefront.promotions.common.edit'), fn: this.editCampaign, permission: 'storefront update campaign' },
                    { id: 'send-campaign', label: this.intl.t('storefront.promotions.campaigns.send-now'), fn: this.sendCampaign, permission: 'storefront send campaign' },
                    { id: 'cancel-campaign', label: this.intl.t('storefront.promotions.campaigns.cancel-campaign'), fn: this.cancelCampaign, permission: 'storefront cancel campaign' },
                    { separator: true },
                    {
                        id: 'delete-campaign',
                        label: this.intl.t('storefront.promotions.common.delete'),
                        fn: this.deleteCampaign,
                        class: 'text-red-700 hover:text-red-800',
                        permission: 'storefront delete campaign',
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

    @action createCampaign() {
        const campaign = this.store.createRecord('campaign', {
            owner_uuid: this.storefront.activeStore.id,
            owner_type: 'storefront:store',
            channels: ['push', 'inbox'],
        });

        return this.editCampaign(campaign, {
            title: this.intl.t('storefront.promotions.campaigns.new-campaign'),
            decline: (modal) => {
                campaign.rollbackAttributes();
                modal.done();
            },
        });
    }

    @action editCampaign(campaign, options = {}) {
        this.modalsManager.show('modals/campaign-form', {
            title: this.intl.t(campaign.isEditable ? 'storefront.promotions.campaigns.edit-campaign' : 'storefront.promotions.campaigns.view-campaign'),
            acceptButtonText: this.intl.t(campaign.status === 'scheduled' ? 'storefront.promotions.campaigns.when-schedule' : 'storefront.promotions.campaigns.when-draft'),
            acceptButtonIcon: campaign.status === 'scheduled' ? 'clock' : 'save',
            acceptButtonDisabled: !campaign.isEditable,
            hideAcceptButton: !campaign.isEditable,
            declineButtonText: this.intl.t(campaign.isEditable ? 'storefront.promotions.common.cancel' : 'storefront.promotions.common.done'),
            modalClass: 'modal-lg',
            campaign,
            when: campaign.status === 'scheduled' ? 'schedule' : 'draft',
            decline: (modal) => {
                campaign.rollbackAttributes();
                modal.done();
            },
            confirm: async (modal) => {
                const when = modal.getOption('when');
                if (!campaign.isEditable) {
                    return;
                }
                if (![campaign.name, campaign.title, campaign.body].every((value) => value?.trim())) {
                    this.notifications.warning(this.intl.t('storefront.promotions.campaigns.required-fields'));
                    return;
                }
                if (!campaign.channels?.length) {
                    this.notifications.warning(this.intl.t('storefront.promotions.campaigns.required-channel'));
                    return;
                }
                if (when === 'schedule' && !(new Date(campaign.send_at).getTime() > Date.now())) {
                    this.notifications.warning(this.intl.t('storefront.promotions.campaigns.required-schedule'));
                    return;
                }
                modal.startLoading();

                campaign.status = when === 'schedule' ? 'scheduled' : 'draft';
                if (when !== 'schedule') {
                    campaign.send_at = null;
                }

                try {
                    await campaign.save();
                    if (when === 'now') {
                        await this.fetch.post(`campaigns/${campaign.id}/send`, {}, { namespace: 'storefront/int/v1' });
                        this.notifications.success(this.intl.t('storefront.promotions.campaigns.sent-success'));
                    } else {
                        this.notifications.success(this.intl.t('storefront.promotions.campaigns.saved-success'));
                    }
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

    @action sendCampaign(campaign) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.promotions.campaigns.send-now'),
            body: this.intl.t('storefront.promotions.campaigns.send-now-confirm'),
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post(`campaigns/${campaign.id}/send`, {}, { namespace: 'storefront/int/v1' });
                    this.notifications.success(this.intl.t('storefront.promotions.campaigns.sent-success'));
                    this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action cancelCampaign(campaign) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.promotions.campaigns.cancel-campaign'),
            body: this.intl.t('storefront.promotions.campaigns.cancel-confirm'),
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post(`campaigns/${campaign.id}/cancel`, {}, { namespace: 'storefront/int/v1' });
                    this.notifications.success(this.intl.t('storefront.promotions.campaigns.canceled-success'));
                    this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action deleteCampaign(campaign) {
        this.crud.delete(campaign, {
            onSuccess: () => this.hostRouter.refresh(),
        });
    }

    /**
     * The columns with what extensions registered under `storefront:campaign:table` merged in.
     *
     * @var {Array}
     */
    @cached get registeredColumns() {
        return this.mergeRegisteredColumns('campaign', this.columns);
    }

    /**
     * Toolbar buttons extensions registered under `storefront:campaign:table:actions`.
     *
     * @var {Array}
     */
    get registeredActionButtons() {
        return this.registeredTableActions('campaign');
    }
}
