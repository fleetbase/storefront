import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debounce } from '@ember/runloop';

/**
 * Create / edit a customer segment with a live count of matching customers.
 */
export default class ModalsCustomerSegmentFormComponent extends Component {
    @service fetch;
    @tracked matching = null;

    numberRules = ['min_orders', 'max_orders', 'ordered_within_days', 'not_ordered_within_days', 'joined_within_days'].map((key) => ({
        key,
        label: `storefront.promotions.segments.${key.replace(/_/g, '-')}`,
    }));

    constructor() {
        super(...arguments);
        this.refreshCount();
    }

    get segment() {
        return this.args.options.segment;
    }

    get hasMatching() {
        return this.matching !== null;
    }

    get rules() {
        return this.segment.rules ?? {};
    }

    setRule(key, value) {
        const rules = { ...this.rules };
        if (value === null || value === '' || value === false) {
            delete rules[key];
        } else {
            rules[key] = value;
        }
        this.segment.rules = rules;
        debounce(this, this.refreshCount, 400);
    }

    @action setNumberRule(key, { target }) {
        this.setRule(key, target.value === '' ? null : Number(target.value));
    }

    @action setMoneyRule(key, value) {
        this.setRule(key, value || null);
    }

    @action setBooleanRule(key, value) {
        this.setRule(key, value);
    }

    async refreshCount() {
        try {
            const { count } = await this.fetch.post('customer-segments/preview', { owner_uuid: this.segment.owner_uuid, rules: this.rules }, { namespace: 'storefront/int/v1' });
            this.matching = count;
        } catch {
            this.matching = null;
        }
    }
}
