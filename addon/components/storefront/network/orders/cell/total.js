import Component from '@glimmer/component';
import { get } from '@ember/object';

function number(value) {
    const parsed = parseFloat(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

export default class StorefrontNetworkOrdersCellTotalComponent extends Component {
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

        return (row?.isGroup ? row.currency : get(row, 'meta.currency') ?? row?.currency) ?? 'USD';
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
}
