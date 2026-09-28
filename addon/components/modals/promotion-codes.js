import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * Lists a promotion's codes and generates new ones with the settings in the form.
 */
export default class ModalsPromotionCodesComponent extends Component {
    @service store;
    @service fetch;
    @service intl;
    @service notifications;
    @tracked codes = [];
    @tracked count = 10;
    @tracked prefix = '';
    @tracked length = 8;
    @tracked usageLimit = 1;
    @tracked specificCode = '';
    @tracked isGenerating = false;

    constructor() {
        super(...arguments);
        this.loadCodes();
    }

    get promotion() {
        return this.args.options.promotion;
    }

    async loadCodes() {
        this.codes = await this.store.query('promotion-code', { promotion: this.promotion.id, limit: 500, sort: '-created_at' });
    }

    @action setValue(property, { target }) {
        this[property] = target.value;
    }

    @action async generate() {
        this.isGenerating = true;

        const body = this.specificCode
            ? { code: this.specificCode, usage_limit: this.usageLimit || null }
            : { count: Number(this.count) || 1, prefix: this.prefix, length: Number(this.length) || 8, usage_limit: this.usageLimit || null };

        try {
            await this.fetch.post(`promotions/${this.promotion.id}/generate-codes`, body, { namespace: 'storefront/int/v1' });
            this.specificCode = '';
            this.promotion.trigger = 'code';
            this.notifications.success(this.intl.t('storefront.promotions.codes.generated-success'));
            await this.loadCodes();
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.isGenerating = false;
        }
    }

    @action async toggleCode(code) {
        code.status = code.status === 'active' ? 'disabled' : 'active';

        try {
            await code.save();
        } catch (error) {
            code.rollbackAttributes();
            this.notifications.serverError(error);
        }
    }
}
