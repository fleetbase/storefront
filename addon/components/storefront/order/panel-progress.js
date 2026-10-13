import StorefrontOrderFlowStepsComponent from './flow-steps';
import { inject as service } from '@ember/service';
import { format, formatDistanceToNowStrict, isValid, parseISO } from 'date-fns';

const NEXT = {
    created: 'accept',
    pending: 'accept',
    accepted: 'ready',
    confirmed: 'ready',
    preparing: 'ready',
    started: 'ready',
};
const FINISHED = ['completed', 'picked_up', 'canceled', 'order_canceled', 'expired', 'failed'];
// Flow codes that stand for the same step as a status (the default flow's step codes).
const STEP_FOR = { preparing: 'accepted', confirmed: 'accepted', pickup_ready: 'ready', driver_enroute: 'dispatched', started: 'dispatched' };

function parse(value) {
    const date = value ? parseISO(value) : null;
    return date && isValid(date) ? date : null;
}

/**
 * The next-step bar and the step strip of the 11a order panel. Step logic (default or custom
 * flow, current step) is the flow steps component's; times come from the order's flow history.
 */
export default class StorefrontOrderPanelProgressComponent extends StorefrontOrderFlowStepsComponent {
    @service storefrontOrderActions;

    get overview() {
        return this.args.overview;
    }

    get isFinished() {
        return FINISHED.includes(this.status);
    }

    get primary() {
        if (this.isFinished) {
            return null;
        }
        return this.storefrontOrderActions.actionItemsFor(this.order, this.args.refresh).find((item) => item.id === 'perform-workflow-action') ?? null;
    }

    get driverName() {
        return this.order?.driver_assigned?.name ?? this.order?.driver_name ?? null;
    }

    get title() {
        const t = (key, options) => this.intl.t(`storefront.order.overview.next.${key}`, options);
        const status = this.status;
        const isPickup = Boolean(this.order?.meta?.is_pickup);

        if (status === 'canceled' || status === 'order_canceled') {
            const reason = this.order?.meta?.cancel_reason;
            return reason ? t('canceled-because', { reason }) : t('canceled');
        }
        if (this.isFinished) {
            return isPickup ? t('picked-up') : t('delivered');
        }
        if (NEXT[status]) {
            return t(NEXT[status]);
        }
        if (['ready', 'pickup_ready', 'ready_for_pickup'].includes(status)) {
            if (isPickup) {
                return t('hand-to-customer');
            }
            return this.driverName ? t('hand-to-driver', { driver: this.driverName }) : t('assign-driver');
        }

        return this.driverName ? t('with-driver', { driver: this.driverName }) : t('on-the-way');
    }

    get dueAt() {
        return parse(this.overview?.due_at);
    }

    get isLate() {
        return !this.isFinished && (this.overview?.late_minutes ?? 0) > 0;
    }

    duration(minutes) {
        const t = (key, options) => this.intl.t(`storefront.order.overview.duration.${key}`, options);
        if (minutes >= 1440) {
            return t('days', { count: Math.floor(minutes / 1440) });
        }
        return minutes >= 60 ? t('hours', { hours: Math.floor(minutes / 60), minutes: minutes % 60 }) : t('minutes', { count: minutes });
    }

    timeLabel(date) {
        return format(date, new Date().toDateString() === date.toDateString() ? 'HH:mm' : 'd MMM HH:mm');
    }

    get dueLine() {
        if (this.isFinished || !this.dueAt) {
            return null;
        }

        const time = this.timeLabel(this.dueAt);
        if (this.isLate) {
            return this.intl.t('storefront.order.overview.due-late', { time, late: this.duration(this.overview.late_minutes) });
        }

        const minutes = Math.max(0, Math.round((this.dueAt.getTime() - Date.now()) / 60000));
        return this.intl.t('storefront.order.panel.due-left', { time, left: this.duration(minutes) });
    }

    /** When each step was reached, from the flow history (keyed by step code). */
    get reachedAt() {
        const reached = {};
        (this.args.flow?.steps ?? []).forEach((step) => {
            const code = STEP_FOR[step.code] ?? step.code;
            if (step.reached_at && !reached[code]) {
                reached[code] = parse(step.reached_at);
            }
        });
        if (!reached.created && this.order?.created_at) {
            reached.created = new Date(this.order.created_at);
        }

        return reached;
    }

    get timedSteps() {
        const reached = this.reachedAt;
        const steps = this.steps;

        return steps.map((step, index) => {
            let time = null;
            if (step.isComplete && reached[step.code]) {
                time = this.timeLabel(reached[step.code]);
            } else if (step.isCurrent && reached[step.code]) {
                time = formatDistanceToNowStrict(reached[step.code]);
            } else if (index === steps.length - 1 && !step.isComplete && this.dueAt) {
                time = this.intl.t('storefront.order.panel.eta', { time: this.timeLabel(this.dueAt) });
            }

            return { ...step, time };
        });
    }
}
