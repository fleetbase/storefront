import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { getOwner } from '@ember/application';

/**
 * A member store seen from its network: who it is, how it is doing in the network this week,
 * and its orders and customers through the network.
 */
export default class NetworksIndexNetworkStoreController extends Controller {
    @service fetch;
    @service store;
    @service intl;
    @service hostRouter;
    @service storefront;
    @service storefrontOrderActions;
    @service notifications;
    @service contextPanel;

    @tracked tab = 'orders';
    @tracked member = null;
    @tracked orders = [];
    @tracked customers = [];

    get network() {
        return this.storefront.activeNetwork;
    }

    get tabs() {
        return [
            { id: 'orders', label: this.intl.t('storefront.common.orders'), count: this.orders.meta?.total ?? this.orders.length, isActive: this.tab === 'orders' },
            { id: 'customers', label: this.intl.t('storefront.common.customers'), count: this.customers.meta?.total ?? this.customers.length, isActive: this.tab === 'customers' },
        ];
    }

    /** Category in the network · currency · id. */
    get subtitle() {
        // The category comes with the membership, so it waits for the load rather than reading "No category".
        const category = this.member ? (this.member.category ?? this.intl.t('storefront.networks.overview.no-category')) : null;

        return [category, this.model?.currency, this.model?.public_id].filter(Boolean).join(' · ');
    }

    get currency() {
        return this.model?.currency ?? this.network?.currency;
    }

    /** The membership as the network sees it: category, status and this week's numbers. */
    get badge() {
        const status = this.member?.membership_status ?? 'active';

        if (status === 'suspended') {
            return { status: 'danger', label: this.intl.t('storefront.networks.index.network.stores.suspended') };
        }

        if (status === 'pending') {
            return { status: 'warning', label: this.intl.t('storefront.networks.index.network.stores.awaiting-approval') };
        }

        return this.model?.online ? { status: 'online', label: this.intl.t('storefront.common.online') } : { status: 'offline', label: this.intl.t('storefront.common.offline') };
    }

    get application() {
        return getOwner(this).lookup('controller:application');
    }

    /** This organisation's own record of the store, when it runs it, so the console can switch into it. */
    get ownStore() {
        const stores = this.application?.model?.stores;
        const list = stores?.toArray?.() ?? Array.from(stores ?? []);

        return list.find((record) => record.public_id === this.model?.public_id) ?? null;
    }

    @task({ restartable: true }) *load(storeRecord) {
        const network = this.network;
        this.member = null;
        this.orders = [];
        this.customers = [];

        if (!network || !storeRecord) {
            return;
        }

        const scope = { storefront: storeRecord.public_id, network: network.public_id };

        try {
            const [overview, ordersResponse, customers] = yield Promise.all([
                this.fetch.get(`networks/${network.id}/overview`, {}, { namespace: 'storefront/int/v1' }),
                this.fetch.get('orders', { ...scope, limit: 25, sort: '-created_at' }, { namespace: 'storefront/int/v1' }),
                this.fetch.get('customers', { ...scope, limit: 25, sort: '-created_at' }, { namespace: 'storefront/int/v1' }),
            ]);

            this.member = (overview?.members ?? []).find((member) => member.public_id === storeRecord.public_id) ?? null;

            const orders = this.fetch.normalizeModel(ordersResponse, 'orders');
            orders.meta = ordersResponse?.meta;
            this.orders = orders;
            // The customer model has no order count, so each row keeps the count beside its record.
            const rows = (customers?.customers ?? []).map((customer) => ({ record: this.store.push(this.store.normalize('customer', customer)), orders: customer.orders ?? 0 }));
            rows.meta = customers?.meta ?? {};
            this.customers = rows;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action selectTab(id) {
        this.tab = id;
    }

    @action viewOrder(order) {
        return this.storefrontOrderActions.viewOrder(order, { onChange: () => this.load.perform(this.model) });
    }

    /** Opens the customer's panel here, as the network customers page does. */
    @action viewCustomer(customer) {
        this.contextPanel.focus(customer, 'viewing');
    }

    @action backToStores() {
        return this.hostRouter.transitionTo('console.storefront.networks.index.network.stores', this.network.public_id);
    }

    /** Switches the console into this store's own context. */
    @action manageStore() {
        if (this.ownStore) {
            return this.application?.switchActiveStore?.(this.ownStore);
        }
    }
}
