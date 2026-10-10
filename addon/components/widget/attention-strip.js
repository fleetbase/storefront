import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatDistanceToNowStrict } from 'date-fns';

/**
 * The things a store operator acts on today, each deep-linking into where it is handled.
 */
export default class WidgetAttentionStripComponent extends Component {
    static widgetId = 'storefront-attention-strip-widget';

    @service fetch;
    @service storefront;
    @service hostRouter;
    @service intl;
    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
        this.storefront.on('order.broadcasted', () => this.load.perform());
        this.storefront.on('storefront.changed', () => this.load.perform());
    }

    get storeId() {
        return this.storefront.activeStore?.public_id ?? this.storefront.activeStore?.id;
    }

    get items() {
        const data = this.data ?? {};
        const oldest = data.oldest_waiting_at ? formatDistanceToNowStrict(new Date(data.oldest_waiting_at)) : null;

        return [
            {
                id: 'confirm',
                count: data.orders_to_confirm ?? 0,
                label: this.intl.t('storefront.dashboard.attention.orders-to-confirm'),
                hint: oldest ? this.intl.t('storefront.dashboard.attention.oldest', { age: oldest }) : null,
                icon: 'bell',
                tone: 'warn',
                route: 'console.storefront.orders.index',
                queryParams: { status: 'created,pending' },
            },
            {
                id: 'pickup',
                count: data.ready_for_pickup ?? 0,
                label: this.intl.t('storefront.dashboard.attention.ready-for-pickup'),
                hint: this.intl.t('storefront.dashboard.attention.customers-notified'),
                icon: 'store',
                tone: 'info',
                route: 'console.storefront.orders.index',
                queryParams: { status: 'ready,pickup_ready' },
            },
            {
                id: 'driver',
                count: data.awaiting_driver ?? 0,
                label: this.intl.t('storefront.dashboard.attention.awaiting-driver'),
                hint: null,
                icon: 'id-card',
                tone: 'warn',
                route: 'console.storefront.orders.index',
                queryParams: { status: 'active' },
            },
            {
                id: 'stock',
                count: data.out_of_stock ?? 0,
                label: this.intl.t('storefront.dashboard.attention.out-of-stock'),
                hint: this.intl.t('storefront.dashboard.attention.still-published'),
                icon: 'box-open',
                tone: 'danger',
                route: 'console.storefront.products.index.index',
                queryParams: { available: false },
            },
            {
                id: 'trucks',
                count: data.trucks_offline ?? 0,
                label: this.intl.t('storefront.dashboard.attention.trucks-offline'),
                hint: null,
                icon: 'truck',
                tone: 'muted',
                route: 'console.storefront.food-trucks',
            },
            {
                id: 'invitations',
                count: data.network_invitations ?? 0,
                label: this.intl.t('storefront.dashboard.attention.network-invitations'),
                hint: (data.invitation_networks ?? []).join(', ') || null,
                icon: 'network-wired',
                tone: 'info',
                route: 'console.storefront.networks',
            },
        ].filter((item) => item.count > 0 || ['confirm', 'pickup'].includes(item.id));
    }

    get allClear() {
        return this.items.every((item) => item.count === 0);
    }

    @task *load() {
        try {
            this.error = null;
            this.data = yield this.fetch.get('analytics/attention', { storefront: this.storeId }, { namespace: 'storefront/int/v1' });
        } catch (error) {
            this.error = error;
        }
    }

    @action open(item) {
        if (item.queryParams) {
            return this.hostRouter.transitionTo(item.route, { queryParams: item.queryParams });
        }

        return this.hostRouter.transitionTo(item.route);
    }
}
