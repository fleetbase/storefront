import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { rankOf } from '../../../utils/order-groups';

const DEFAULT_DELIVERY_FLOW = ['created', 'accepted', 'ready', 'dispatched', 'completed'];
const DEFAULT_PICKUP_FLOW = ['created', 'accepted', 'pickup_ready', 'picked_up'];
const ALIASES = {
    preparing: 'accepted',
    ready: 'pickup_ready',
    driver_enroute: 'dispatched',
    en_route: 'dispatched',
    out_for_delivery: 'dispatched',
};
const CANCELED = ['canceled', 'cancel', 'order_canceled'];

/**
 * The order's activity flow as numbered steps with the current one marked. A custom
 * order config contributes its own steps; the default storefront flow is implied.
 */
export default class StorefrontOrderFlowStepsComponent extends Component {
    @service storefrontOrderWorkflow;
    @service intl;

    get order() {
        return this.args.order ?? this.args.resource;
    }

    get status() {
        return this.storefrontOrderWorkflow.statusFor(this.order);
    }

    get isCanceled() {
        return CANCELED.includes(this.status);
    }

    get isPickup() {
        return this.storefrontOrderWorkflow.isPickupOrder(this.order);
    }

    /**
     * Step codes: walked from `created` through a custom flow's first activities, else the default.
     */
    get codes() {
        const flow = this.order?.order_config?.flow;

        if (flow && !this.storefrontOrderWorkflow.isDefaultStorefrontConfig(this.order)) {
            const codes = [];
            let code = flow.created ? 'created' : Object.keys(flow)[0];

            while (code && !codes.includes(code) && codes.length < 10) {
                codes.push(code);
                const next = flow[code]?.activities;
                code = Array.isArray(next) ? next.find((candidate) => !CANCELED.includes(candidate)) : null;
            }

            return codes;
        }

        return this.isPickup ? DEFAULT_PICKUP_FLOW : DEFAULT_DELIVERY_FLOW;
    }

    get currentIndex() {
        const status = ALIASES[this.status] ?? this.status;
        const index = this.codes.indexOf(status);

        if (index >= 0) {
            return index;
        }

        // An unknown status still lands on the nearest step by rank.
        const rank = rankOf(this.status);
        let nearest = 0;
        this.codes.forEach((code, position) => {
            if (rankOf(code) <= rank) {
                nearest = position;
            }
        });

        return nearest;
    }

    get steps() {
        const flow = this.order?.order_config?.flow ?? {};
        const current = this.currentIndex;

        return this.codes.map((code, index) => ({
            code,
            number: index + 1,
            label: this.labelFor(code, flow[code]?.status),
            isComplete: index < current || (index === current && rankOf(this.status) >= 4),
            isCurrent: index === current && rankOf(this.status) < 4,
            isUpcoming: index > current,
        }));
    }

    /** The short translated step name wins; a custom flow's own status name is the fallback. */
    labelFor(code, fallback = null) {
        const key = `storefront.order.flow.${code}`;

        if (!this.intl.exists(key) && fallback) {
            return fallback;
        }

        return this.intl.exists(key) ? this.intl.t(key) : String(code).replace(/_/g, ' ');
    }
}
