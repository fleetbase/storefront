import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Everything a support agent needs on one screen: contact facts, saved places,
 * the stores ordered from, and the most recent orders.
 */
export default class CustomerPanelOverviewComponent extends Component {
    @service fetch;
    @service storefront;
    @service storefrontOrderActions;
    @service notifications;
    @service hostRouter;
    @service contextPanel;
    @tracked orders = [];

    constructor() {
        super(...arguments);
        this.loadOrders.perform();
    }

    get customer() {
        return this.args.customer;
    }

    get insights() {
        return this.args.insights ?? this.args.options?.insights ?? null;
    }

    get places() {
        const places = this.customer?.places;

        return places?.toArray?.() ?? Array.from(places ?? []);
    }

    get defaultPlaceId() {
        return this.customer?.place_uuid ?? this.customer?.place?.id ?? null;
    }

    get placeRows() {
        return this.places.map((place) => ({ place, isDefault: place.id === this.defaultPlaceId }));
    }

    /** Where the customer ordered; in a network, each member store opens its details (an order placed on the network itself does not). */
    get stores() {
        const canOpen = this.storefront.isNetworkContext;

        return (this.insights?.stores ?? []).map((store) => ({ ...store, canOpen: canOpen && String(store.public_id ?? '').startsWith('store_') }));
    }

    /** The segments whose rules this customer matches, from the insights endpoint. */
    get segments() {
        return this.insights?.segments ?? [];
    }

    @action openSegment(segment) {
        return this.hostRouter.transitionTo('console.storefront.promotions.segments', { queryParams: { segment: segment.public_id ?? segment.id } });
    }

    @action manageSegments() {
        return this.hostRouter.transitionTo('console.storefront.promotions.segments');
    }

    /** In a network, a store the customer ordered from opens that store's details. */
    @action openStore(store) {
        const network = this.storefront.activeNetwork;

        if (!network || !store?.public_id) {
            return;
        }

        this.contextPanel.clear();
        return this.hostRouter.transitionTo('console.storefront.networks.index.network.store', network.public_id, store.public_id);
    }

    get showStores() {
        return this.storefront.isNetworkContext || this.stores.length > 1;
    }

    get accountLabel() {
        const status = this.customer?.login_status ?? (this.customer?.user_uuid ? 'app' : null);

        return status;
    }

    @task *loadOrders() {
        if (!this.customer?.id) {
            return;
        }

        const scope = this.storefront.contextScope();

        try {
            const orders = yield this.fetch.get(
                'orders',
                { ...scope, limit: 5, sort: '-created_at', customer_uuid: this.customer.id },
                { namespace: 'storefront/int/v1', normalizeToEmberData: true }
            );
            this.orders = orders?.toArray?.() ?? Array.from(orders ?? []);
        } catch {
            this.orders = [];
        }
    }

    storeNameOf(order) {
        return get(order, 'meta.storefront');
    }

    @action viewOrder(order) {
        return this.storefrontOrderActions.viewOrder(order, { onChange: () => this.loadOrders.perform() });
    }

    @action viewAllOrders() {
        return this.args.onViewOrders?.();
    }
}
