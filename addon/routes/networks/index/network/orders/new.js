import OrdersIndexNewRoute from '../../../../orders/index/new';
import { hash } from 'rsvp';

/**
 * Create an order from the network context: the same form as the store's, with a store
 * step first. The order is placed with that store (one store per order; a multi-store
 * cart is still placed from the app).
 */
export default class NetworksIndexNetworkOrdersNewRoute extends OrdersIndexNewRoute {
    templateName = 'orders/index/new';
    controllerName = 'orders/index/new';

    get network() {
        return this.modelFor('networks.index.network');
    }

    model() {
        return hash({
            activeStore: null,
            locations: [],
            products: [],
            stores: this.store.query('store', { network: this.network.id, limit: -1, sort: 'name' }).catch(() => []),
        });
    }

    async setupController(controller, model, transition) {
        await super.setupController(controller, model, transition);
        controller.network = this.network;
        controller.storeOptions = (model.stores?.toArray?.() ?? Array.from(model.stores ?? [])).filter((store) => store.network_status !== 'suspended' && store.network_status !== 'pending');
        controller.returnRoute = 'console.storefront.networks.index.network.orders';
        controller.returnModel = this.network.public_id;
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);

        if (isExiting) {
            controller.network = null;
            controller.storeOptions = [];
            controller.returnRoute = 'console.storefront.orders.index';
            controller.returnModel = null;
        }
    }
}
