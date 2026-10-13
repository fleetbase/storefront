import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { format, isValid, parseISO } from 'date-fns';

const NEXT = {
    created: 'accept',
    pending: 'accept',
    accepted: 'ready',
    confirmed: 'ready',
    preparing: 'ready',
    started: 'ready',
};
const FINISHED = ['completed', 'picked_up', 'canceled', 'order_canceled', 'expired', 'failed'];

/**
 * One line on what the store does next with the order, and one on when it is due.
 */
export default class StorefrontOrderNextStepComponent extends Component {
    @service intl;
    @service storefrontOrderWorkflow;

    get order() {
        return this.args.order;
    }

    get status() {
        return this.storefrontOrderWorkflow.statusFor(this.order) ?? this.order?.status;
    }

    get isFinished() {
        return FINISHED.includes(this.status);
    }

    get isPickup() {
        return Boolean(this.order?.meta?.is_pickup);
    }

    get driverName() {
        return this.order?.driver_assigned?.name ?? this.order?.driver_name ?? null;
    }

    get title() {
        const t = (key, options) => this.intl.t(`storefront.order.overview.next.${key}`, options);
        const status = this.status;

        if (status === 'canceled' || status === 'order_canceled') {
            const reason = this.order?.meta?.cancel_reason;
            return reason ? t('canceled-because', { reason }) : t('canceled');
        }
        if (this.isFinished) {
            return this.isPickup ? t('picked-up') : t('delivered');
        }
        if (NEXT[status]) {
            return t(NEXT[status]);
        }
        if (['ready', 'pickup_ready', 'ready_for_pickup'].includes(status)) {
            if (this.isPickup) {
                return t('hand-to-customer');
            }
            return this.driverName ? t('hand-to-driver', { driver: this.driverName }) : t('assign-driver');
        }

        return this.driverName ? t('with-driver', { driver: this.driverName }) : t('on-the-way');
    }

    get dueAt() {
        const value = this.args.overview?.due_at;
        const date = value ? parseISO(value) : null;

        return date && isValid(date) ? date : null;
    }

    get isLate() {
        return !this.isFinished && (this.args.overview?.late_minutes ?? 0) > 0;
    }

    /** Minutes as "12 min", "3 h 5 min" or "2 days". */
    duration(minutes) {
        const t = (key, options) => this.intl.t(`storefront.order.overview.duration.${key}`, options);
        if (minutes >= 1440) {
            return t('days', { count: Math.floor(minutes / 1440) });
        }
        if (minutes >= 60) {
            return t('hours', { hours: Math.floor(minutes / 60), minutes: minutes % 60 });
        }

        return t('minutes', { count: minutes });
    }

    get dueLine() {
        if (this.isFinished || !this.dueAt) {
            return null;
        }

        const sameDay = new Date().toDateString() === this.dueAt.toDateString();
        const time = format(this.dueAt, sameDay ? 'HH:mm' : 'd MMM HH:mm');
        if (this.isLate) {
            return this.intl.t('storefront.order.overview.due-late', { time, late: this.duration(this.args.overview.late_minutes) });
        }

        const minutes = Math.max(0, Math.round((this.dueAt.getTime() - Date.now()) / 60000));
        return this.intl.t('storefront.order.overview.due-in', { time, minutes });
    }
}
