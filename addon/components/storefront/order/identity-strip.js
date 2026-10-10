import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { isArray } from '@ember/array';

function number(value) {
    const parsed = parseFloat(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

/**
 * The dispatcher's first glance: who it is for, who delivers it, how it is fulfilled, what it costs, when.
 */
export default class StorefrontOrderIdentityStripComponent extends Component {
    @service storefrontOrderActions;
    @service storefrontOrderWorkflow;

    get order() {
        return this.args.order ?? this.args.resource;
    }

    get isPickup() {
        return this.storefrontOrderWorkflow.isPickupOrder(this.order);
    }

    get isTerminal() {
        return this.storefrontOrderWorkflow.isTerminal(this.order);
    }

    get hasDriver() {
        return this.storefrontOrderWorkflow.hasAssignedDriver(this.order);
    }

    get canAssignDriver() {
        return !this.isPickup && !this.hasDriver && !this.isTerminal;
    }

    get currency() {
        return get(this.order, 'meta.currency') ?? this.order?.currency ?? 'USD';
    }

    get total() {
        return number(get(this.order, 'meta.total') ?? this.order?.transaction_amount);
    }

    get tip() {
        return number(get(this.order, 'meta.tip')) + number(get(this.order, 'meta.delivery_tip'));
    }

    get discount() {
        return number(get(this.order, 'meta.discount'));
    }

    get promotionCode() {
        const promotions = get(this.order, 'meta.promotions');

        if (isArray(promotions) && promotions.length) {
            const first = promotions[0];

            return typeof first === 'string' ? first : (first?.code ?? first?.name ?? null);
        }

        return null;
    }

    get scheduledAt() {
        return this.order?.scheduledAt ?? null;
    }

    get bookingAt() {
        return get(this.order, 'meta.booking_at') ?? null;
    }

    @action assignDriver() {
        return this.storefrontOrderActions.assignDriver(this.order, this.args.onChange);
    }
}
