import StorefrontOrderFlowStepsComponent from './flow-steps';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { format, formatDistanceToNowStrict, isPast, isValid, parseISO } from 'date-fns';

const FINISHED = ['completed', 'picked_up', 'canceled', 'order_canceled', 'expired', 'failed'];
// Statuses with their own Storefront action (accepting, and marking ready, which dispatches).
const STORE_ACTIONS = ['created', 'pending', 'accepted', 'confirmed'];

/*
 * The default Storefront flow runs created → dispatched → started → preparing → driver en route
 * to the store → driver picked up → driver en route → completed (or ready for pickup → picked up).
 * The store thinks of it in five steps; each step stands for the statuses below. A custom order
 * config shows its own steps as they are.
 */
const DELIVERY_STEPS = [
    ['created', ['created', 'pending']],
    ['accepted', ['accepted', 'confirmed']],
    ['ready', ['ready', 'preparing', 'dispatched', 'started', 'driver_enroute_to_store']],
    ['dispatched', ['driver_picked_up', 'driver_enroute', 'en_route', 'out_for_delivery']],
    ['completed', ['completed']],
];
// A service booking (the Storefront booking flow): at the customer's address, or at the store.
const BOOKING_STEPS = [
    ['booked', ['created', 'pending']],
    ['confirmed', ['accepted', 'confirmed']],
    ['on_the_way', ['dispatched', 'started', 'provider_arrived']],
    ['in_progress', ['in_progress']],
    ['done', ['completed']],
];
const PICKUP_STEPS = [
    ['created', ['created', 'pending']],
    ['accepted', ['accepted', 'confirmed', 'dispatched', 'started', 'preparing']],
    ['pickup_ready', ['pickup_ready', 'ready', 'ready_for_pickup']],
    ['picked_up', ['picked_up']],
];

function parse(value) {
    const date = value ? parseISO(value) : null;
    return date && isValid(date) ? date : null;
}

/**
 * The next-step bar and the step strip of the 11a order panel, from the order's own flow (the
 * server resolves its steps, the next steps its conditions allow, and the step that completes
 * it). Accepting and marking ready keep their Storefront actions; after that the order moves
 * along its flow, step by step or straight to completion.
 */
export default class StorefrontOrderPanelProgressComponent extends StorefrontOrderFlowStepsComponent {
    @service storefrontOrderActions;

    get overview() {
        return this.args.overview;
    }

    get flow() {
        return this.args.flow ?? null;
    }

    get isFinished() {
        return FINISHED.includes(this.status) || Boolean(this.flow?.completed);
    }

    get isDefaultConfig() {
        return this.storefrontOrderWorkflow.isDefaultStorefrontConfig(this.order);
    }

    get isBooking() {
        return Boolean(this.order?.meta?.is_booking);
    }

    /** The panel's refresh, looked up when needed: the panel sets it after it loads. */
    reload() {
        (this.storefrontOrderActions.panel.refresh ?? this.args.refresh)?.();
    }

    /* Steps */

    get groups() {
        if (this.isBooking) {
            // At the store the customer comes in: nobody is on the way.
            return this.isPickup ? BOOKING_STEPS.filter(([code]) => code !== 'on_the_way') : BOOKING_STEPS;
        }

        return this.isPickup ? PICKUP_STEPS : DELIVERY_STEPS;
    }

    get flowSteps() {
        return this.flow?.steps ?? [];
    }

    /** When each status was reached, from the flow's history. */
    get reached() {
        const reached = {};
        this.flowSteps.forEach((step) => {
            const at = parse(step.reached_at);
            if (at && !reached[step.code]) {
                reached[step.code] = at;
            }
        });
        if (!reached.created && this.order?.created_at) {
            reached.created = new Date(this.order.created_at);
        }

        return reached;
    }

    get steps() {
        if (!this.isBooking && !this.isDefaultConfig && this.flowSteps.length) {
            return this.flowSteps.map((step, index) => ({
                code: step.code,
                number: index + 1,
                label: step.label,
                isComplete: step.state === 'done',
                isCurrent: step.state === 'current',
                isUpcoming: step.state === 'upcoming',
                reachedAt: parse(step.reached_at),
            }));
        }

        const status = this.status;
        const reached = this.reached;
        const finished = FINISHED.includes(status);
        let current = this.groups.findIndex(([, codes]) => codes.includes(status));
        if (current < 0) {
            // A status the steps do not name lands on the last step it has been through.
            current = this.groups.reduce((last, [, codes], index) => (codes.some((code) => reached[code]) ? index : last), 0);
        }

        return this.groups.map(([code, codes], index) => ({
            code,
            number: index + 1,
            label: this.isBooking ? this.intl.t(`storefront.order.booking-steps.${code}`) : this.labelFor(code),
            isComplete: index < current || (index === current && finished),
            isCurrent: index === current && !finished,
            isUpcoming: index > current,
            reachedAt: codes.map((member) => reached[member]).find(Boolean) ?? null,
        }));
    }

    timeLabel(date) {
        return format(date, new Date().toDateString() === date.toDateString() ? 'HH:mm' : 'd MMM HH:mm');
    }

    get timedSteps() {
        const steps = this.steps;
        // A booking is done when its time ends.
        const doneBy = this.isBooking ? parse(this.order?.meta?.booking?.end) : this.dueAt;

        return steps.map((step, index) => {
            let time = null;
            if (step.isComplete && step.reachedAt) {
                time = this.timeLabel(step.reachedAt);
            } else if (step.isCurrent && step.reachedAt) {
                time = formatDistanceToNowStrict(step.reachedAt);
            } else if (index === steps.length - 1 && !step.isComplete && doneBy) {
                time = this.intl.t('storefront.order.panel.eta', { time: this.timeLabel(doneBy) });
            }

            return { ...step, time };
        });
    }

    /* What to do next */

    /** Accepting and marking ready (which dispatches) keep their Storefront actions. */
    get storeAction() {
        if (!STORE_ACTIONS.includes(this.status)) {
            return null;
        }

        return this.storefrontOrderActions.actionItemsFor(this.order, () => this.reload()).find((item) => item.id === 'perform-workflow-action') ?? null;
    }

    get nextOptions() {
        return this.flow?.next ?? [];
    }

    get expectedNext() {
        return this.nextOptions.find((option) => option.expected) ?? this.nextOptions[0] ?? null;
    }

    optionText(option) {
        if (option.complete) {
            return this.isPickup ? this.intl.t('storefront.order.workflow.mark-picked-up') : this.intl.t('storefront.order.panel.complete-order');
        }

        return this.intl.t('storefront.order.panel.move-to', { step: option.label });
    }

    get primary() {
        if (this.isFinished) {
            return null;
        }
        if (this.storeAction) {
            return this.storeAction;
        }

        const option = this.expectedNext;
        return option ? { text: this.optionText(option), icon: option.complete ? 'check' : 'forward', fn: () => this.moveTo(option) } : null;
    }

    /** Other steps the flow allows now, and finishing the order straight away. */
    get moreOptions() {
        if (this.isFinished || this.storeAction) {
            return [];
        }

        const primary = this.expectedNext;
        const others = this.nextOptions.filter((option) => option !== primary).map((option) => ({ text: this.optionText(option), option }));
        const completion = this.flow?.completion;
        if (completion && !this.nextOptions.some((option) => option.code === completion.code)) {
            others.push({ text: this.intl.t('storefront.order.panel.complete-now'), option: completion });
        }

        return others;
    }

    @action moveTo(option, dropdown) {
        dropdown?.actions?.close?.();
        return this.storefrontOrderActions.moveToActivity(this.order, option, () => this.reload());
    }

    get driverName() {
        return this.order?.driver_assigned?.name ?? this.order?.driver_name ?? null;
    }

    get title() {
        const t = (key, options) => this.intl.t(`storefront.order.overview.next.${key}`, options);
        const status = this.status;
        const isPickup = this.isPickup;

        if (status === 'canceled' || status === 'order_canceled') {
            const reason = this.order?.meta?.cancel_reason;
            return reason ? t('canceled-because', { reason }) : t('canceled');
        }
        if (this.isFinished) {
            return isPickup ? t('picked-up') : t('delivered');
        }
        if (this.isBooking) {
            if (['created', 'pending'].includes(status)) {
                return t('confirm-booking');
            }
            if (['accepted', 'confirmed'].includes(status)) {
                return isPickup ? t('start-when-arrives', { name: this.customerFirstName }) : t('send-provider');
            }
            const next = this.expectedNext;
            return next ? t('next-step', { step: next.label }) : t('in-progress', { step: this.flowSteps.find((item) => item.state === 'current')?.label ?? status });
        }
        if (['created', 'pending'].includes(status)) {
            return t('accept');
        }
        if (['accepted', 'confirmed'].includes(status)) {
            return t('ready');
        }

        if (this.isDefaultConfig) {
            if (isPickup) {
                return t('hand-to-customer');
            }
            const step = this.steps.find((item) => item.isCurrent)?.code;
            if (step === 'ready') {
                return this.driverName ? t('hand-to-driver', { driver: this.driverName }) : t('assign-driver');
            }

            return this.driverName ? t('with-driver', { driver: this.driverName }) : t('on-the-way');
        }

        const next = this.expectedNext;
        return next ? t('next-step', { step: next.label }) : t('in-progress', { step: this.flowSteps.find((item) => item.state === 'current')?.label ?? status });
    }

    get customerFirstName() {
        return String(this.order?.customer?.name ?? '').split(' ')[0];
    }

    /** "Tue 14 Oct, 10:00 to 13:00 · starts in 2 days · Mei Tan". */
    get bookingLine() {
        if (!this.isBooking) {
            return null;
        }

        const booking = this.order?.meta?.booking ?? {};
        const start = parse(booking.start ?? this.order?.meta?.booking_at);
        if (!start) {
            return null;
        }

        const end = parse(booking.end);
        const when = end
            ? this.intl.t('storefront.order.panel.booking-when', { day: format(start, 'EEE d MMM'), start: format(start, 'HH:mm'), end: format(end, 'HH:mm') })
            : format(start, 'EEE d MMM, HH:mm');
        const relative = this.isFinished
            ? null
            : isPast(start)
              ? this.intl.t('storefront.order.panel.booking-started', { ago: formatDistanceToNowStrict(start) })
              : this.intl.t('storefront.order.panel.booking-starts', { in: formatDistanceToNowStrict(start) });

        return [when, relative, this.driverName].filter(Boolean).join(' · ');
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

    get dueLine() {
        if (this.isBooking) {
            return null;
        }
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
}
