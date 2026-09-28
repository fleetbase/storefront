import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * Create / edit a campaign: message, audience, channels, deep link and when to send.
 */
export default class ModalsCampaignFormComponent extends Component {
    @service store;
    @service fetch;
    @service modalsManager;
    @tracked segment = null;
    @tracked promotion = null;
    @tracked audienceCount = null;

    whenOptions = ['draft', 'now', 'schedule'];

    constructor() {
        super(...arguments);
        this.load();
    }

    get campaign() {
        return this.args.options.campaign;
    }

    get when() {
        return this.args.options.when;
    }

    get hasAudienceCount() {
        return this.audienceCount !== null;
    }

    get linkUrl() {
        return this.campaign.action?.type === 'url' ? this.campaign.action.url : '';
    }

    hasChannel = (channel) => (this.campaign.channels ?? []).includes(channel);

    async load() {
        if (this.campaign.segment_uuid) {
            this.segment = await this.store.findRecord('customer-segment', this.campaign.segment_uuid).catch(() => null);
        }
        if (this.campaign.promotion_uuid) {
            this.promotion = await this.store.findRecord('promotion', this.campaign.promotion_uuid).catch(() => null);
        }
        if (!this.campaign.isNew) {
            const { count } = await this.fetch.get(`campaigns/${this.campaign.id}/audience`, {}, { namespace: 'storefront/int/v1' }).catch(() => ({ count: null }));
            this.audienceCount = count;
        }
    }

    @action setWhen(when) {
        this.modalsManager.setOption('when', when);
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
}
