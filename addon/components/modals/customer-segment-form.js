import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { cancel, debounce } from '@ember/runloop';

/**
 * Create / edit a customer segment with a live count of matching customers.
 */
export default class ModalsCustomerSegmentFormComponent extends Component {
    @service fetch;
    @tracked matching = null;
    @tracked isLoadingCount = false;
    @tracked countUnavailable = false;
    countDebounce = null;
    countRevision = 0;

    numberRules = ['min_orders', 'max_orders', 'ordered_within_days', 'not_ordered_within_days', 'joined_within_days'].map((key) => ({
        key,
        label: `storefront.promotions.segments.${key.replace(/_/g, '-')}`,
        minimum: key.endsWith('_days') ? 1 : 0,
        placeholder: key.endsWith('_days') ? 'storefront.promotions.segments.days-placeholder' : 'storefront.promotions.segments.orders-placeholder',
    }));

    constructor() {
        super(...arguments);
        this.refreshCount();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        cancel(this.countDebounce);
        this.countRevision++;
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
        this.countRevision++;
        this.matching = null;
        this.countUnavailable = false;
        this.isLoadingCount = true;
        this.countDebounce = debounce(this, this.refreshCount, 400);
    }

    @action setNumberRule(key, { target }) {
        this.setRule(key, target.value === '' ? null : Number(target.value));
    }

    @action setMoneyRule(key, value) {
        this.setRule(key, Number(value) === 0 ? null : value);
    }

    @action setBooleanRule(key, value) {
        this.setRule(key, value);
    }

    async refreshCount() {
        if (this.isDestroying || this.isDestroyed) {
            return;
        }

        const revision = ++this.countRevision;
        this.isLoadingCount = true;
        this.countUnavailable = false;
        try {
            const { count } = await this.fetch.post('customer-segments/preview', { owner_uuid: this.segment.owner_uuid, rules: this.rules }, { namespace: 'storefront/int/v1' });
            if (!this.isDestroying && !this.isDestroyed && revision === this.countRevision) {
                this.matching = count;
            }
        } catch {
            if (!this.isDestroying && !this.isDestroyed && revision === this.countRevision) {
                this.matching = null;
                this.countUnavailable = true;
            }
        } finally {
            if (!this.isDestroying && !this.isDestroyed && revision === this.countRevision) {
                this.isLoadingCount = false;
            }
        }
    }
}
