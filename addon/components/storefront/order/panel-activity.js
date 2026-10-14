import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { isValid, parseISO } from 'date-fns';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

/**
 * The order's timeline (steps, notes, edits, payments, messages), loaded when opened.
 */
export default class StorefrontOrderPanelActivityComponent extends Component {
    @service fetch;
    @service storefrontOrderActions;
    @service intl;
    @tracked entries = null;

    /** A time on the order's store clock (date-fns pattern), not the browser's. */
    storeTime(value, pattern) {
        return this.storefrontOrderActions.formatForOrder(this.args.order ?? this.order, value, pattern);
    }

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    @task *load() {
        try {
            const response = yield this.fetch.get(`orders/${this.args.order.public_id}/activity`, {}, { namespace: 'storefront/int/v1' });
            this.entries = response?.activity ?? [];
        } catch {
            this.entries = [];
        }
    }

    @action opened(isOpen) {
        if (isOpen) {
            this.load.perform();
        }
    }

    get rows() {
        const t = (key, options) => this.intl.t(`storefront.order.panel.activity.${key}`, options);

        return (this.entries ?? []).map((entry) => {
            const at = entry.at ? parseISO(entry.at) : null;
            let text;
            switch (entry.type) {
                case 'note':
                    text = t('note', { name: entry.by ?? this.intl.t('storefront.common.store'), content: entry.body });
                    break;
                case 'edit':
                    text = t('edit', { name: entry.by ?? this.intl.t('storefront.common.store'), summary: entry.body ?? '' });
                    break;
                case 'payment':
                    text = t(`payment-${entry.title ?? 'refund'}`, { amount: formatCurrency(Math.abs(entry.meta?.amount ?? 0), entry.meta?.currency) });
                    break;
                case 'message':
                    text = t('message', { name: entry.by ?? '', content: entry.body ?? '' });
                    break;
                default:
                    text = entry.title ?? entry.body ?? '';
            }

            return { text, time: at && isValid(at) ? this.storeTime(at, 'd MMM HH:mm') : '' };
        });
    }

    get summary() {
        if (!this.entries) {
            return null;
        }
        const last = this.rows[0]?.time;
        return this.intl.t('storefront.order.panel.events', { count: this.entries.length }) + (last ? ` · ${this.intl.t('storefront.order.panel.last', { time: last })}` : '');
    }
}
