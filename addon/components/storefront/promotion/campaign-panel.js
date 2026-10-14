import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Create, edit or view a campaign in the shared right-hand panel: message, audience,
 * channels, deep link and when it goes out, with the save bar deciding between draft,
 * schedule and send now. Replaces the campaign modal.
 */
export default class StorefrontPromotionCampaignPanelComponent extends Component {
    @service store;
    @service fetch;
    @service intl;
    @service notifications;

    @tracked segment = null;
    @tracked promotion = null;
    @tracked audienceCount = null;
    @tracked when = 'draft';
    savedSegmentUuid = null;
    whenOptions = ['draft', 'now', 'schedule'];

    constructor() {
        super(...arguments);
        this.when = this.args.when ?? (this.campaign.status === 'scheduled' ? 'schedule' : 'draft');
        this.savedSegmentUuid = this.campaign.segment_uuid;
        this.load();
    }

    get campaign() {
        return this.args.campaign;
    }

    get title() {
        if (this.campaign.isNew) {
            return this.intl.t('storefront.promotions.campaigns.new-campaign');
        }

        return this.intl.t(this.campaign.isEditable ? 'storefront.promotions.campaigns.edit-campaign' : 'storefront.promotions.campaigns.view-campaign');
    }

    get isReadOnly() {
        return !this.campaign.isEditable;
    }

    get isDirty() {
        return Boolean(this.campaign.isNew || this.campaign.hasDirtyAttributes);
    }

    get hasAudienceCount() {
        return this.audienceCount !== null && this.campaign.segment_uuid === this.savedSegmentUuid;
    }

    get linkUrl() {
        return this.campaign.action?.type === 'url' ? this.campaign.action.url : '';
    }

    get saveLabel() {
        return this.intl.t(`storefront.promotions.campaigns.when-${this.when}`);
    }

    get saveIcon() {
        return this.when === 'now' ? 'paper-plane' : this.when === 'schedule' ? 'clock' : 'save';
    }

    hasChannel = (channel) => (this.campaign.channels ?? []).includes(channel);

    async load() {
        if (this.campaign.segment_uuid) {
            const segment = await this.store.findRecord('customer-segment', this.campaign.segment_uuid).catch(() => null);
            if (this.isDestroying || this.isDestroyed) {
                return;
            }
            if (this.campaign.segment_uuid === this.savedSegmentUuid) {
                this.segment = segment;
            }
        }

        if (this.campaign.promotion_uuid) {
            const promotionUuid = this.campaign.promotion_uuid;
            const promotion = await this.store.findRecord('promotion', promotionUuid).catch(() => null);
            if (this.isDestroying || this.isDestroyed) {
                return;
            }
            if (this.campaign.promotion_uuid === promotionUuid) {
                this.promotion = promotion;
            }
        }

        if (!this.campaign.isNew) {
            const { count } = await this.fetch.get(`campaigns/${this.campaign.id}/audience`, {}, { namespace: 'storefront/int/v1' }).catch(() => ({ count: null }));
            if (!this.isDestroying && !this.isDestroyed) {
                this.audienceCount = count;
            }
        }
    }

    @action setWhen(when) {
        this.when = when;
    }

    @action setSegment(segment) {
        this.segment = segment;
        this.campaign.segment_uuid = segment?.id ?? null;
    }

    @action setPromotion(promotion) {
        this.promotion = promotion;
        this.campaign.promotion_uuid = promotion?.id ?? null;
        this.campaign.action = promotion ? { type: 'promotion', id: promotion.public_id } : null;
    }

    @action setLinkUrl({ target }) {
        this.campaign.action = target.value ? { type: 'url', url: target.value } : null;
    }

    @action toggleChannel(channel, enabled) {
        const channels = new Set(this.campaign.channels ?? []);
        if (enabled) {
            channels.add(channel);
        } else {
            channels.delete(channel);
        }
        this.campaign.channels = [...channels];
    }

    /** Validation and the draft / schedule / send-now branches, as the modal's confirm did. */
    @task *save() {
        const { campaign, when } = this;

        if (!campaign.isEditable) {
            return;
        }
        if (![campaign.name, campaign.title, campaign.body].every((value) => value?.trim())) {
            return this.notifications.warning(this.intl.t('storefront.promotions.campaigns.required-fields'));
        }
        if (!campaign.channels?.length) {
            return this.notifications.warning(this.intl.t('storefront.promotions.campaigns.required-channel'));
        }
        if (when === 'schedule' && !(new Date(campaign.send_at).getTime() > Date.now())) {
            return this.notifications.warning(this.intl.t('storefront.promotions.campaigns.required-schedule'));
        }

        campaign.status = when === 'schedule' ? 'scheduled' : 'draft';
        if (when !== 'schedule') {
            campaign.send_at = null;
        }

        try {
            yield campaign.save();
            if (when === 'now') {
                yield this.fetch.post(`campaigns/${campaign.id}/send`, {}, { namespace: 'storefront/int/v1' });
                this.notifications.success(this.intl.t('storefront.promotions.campaigns.sent-success'));
            } else {
                this.notifications.success(this.intl.t('storefront.promotions.campaigns.saved-success'));
            }
            this.args.onSaved?.(campaign);
            this.args.onClose?.();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action close() {
        if (typeof this.campaign.rollbackAttributes === 'function') {
            this.campaign.rollbackAttributes();
        }

        this.args.onClose?.();
    }
}
