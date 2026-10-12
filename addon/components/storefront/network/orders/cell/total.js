import Component from '@glimmer/component';
import { get } from '@ember/object';
import { inject as service } from '@ember/service';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

function number(value) {
    const parsed = parseFloat(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

export default class StorefrontNetworkOrdersCellTotalComponent extends Component {
    @service intl;

    get total() {
        const { row } = this.args;

        return row?.isGroup ? row.total : number(get(row, 'meta.total') ?? row?.transaction_amount);
    }

    get tip() {
        const { row } = this.args;

        return row?.isGroup ? row.tip : number(get(row, 'meta.tip')) + number(get(row, 'meta.delivery_tip'));
    }

    get currency() {
        const { row } = this.args;

        return (row?.isGroup ? row.currency : (get(row, 'meta.currency') ?? row?.currency)) ?? 'USD';
    }

    get tipLabel() {
        if (!this.tip) {
            return null;
        }

        const { row } = this.args;

        if (row?.isGroup) {
            return row.orderCount > 1 ? 'split' : 'network';
        }

        return get(row, 'meta.checkout_id') ? 'shared' : 'store';
    }

    /** The tip as a sentence: how a checkout splits it, or what an order's share is. */
    get tipText() {
        const label = this.tipLabel;

        if (!label) {
            return null;
        }

        const tip = formatCurrency(this.tip, this.currency);
        const keys = { split: 'tip-split', shared: 'tip-shared', network: 'tip-to-store', store: 'tip-to-store' };

        return this.intl.t(`storefront.networks.orders.${keys[label]}`, { tip, count: this.args.row?.orderCount });
    }
}
