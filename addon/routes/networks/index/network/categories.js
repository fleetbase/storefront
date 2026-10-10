import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * The network's store categories with the stores in each.
 */
export default class NetworksIndexNetworkCategoriesRoute extends Route {
    @service store;

    get network() {
        return this.modelFor('networks.index.network');
    }

    model() {
        return this.store.query('category', { owner_uuid: this.network.id, for: 'storefront_network', with_parent: true, limit: -1 });
    }

    async setupController(controller, model) {
        super.setupController(controller, model);
        controller.network = this.network;

        try {
            const stores = await this.store.query('store', { network: this.network.id, with_category: 1, limit: -1 });
            controller.stores = stores?.toArray?.() ?? Array.from(stores ?? []);
        } catch {
            controller.stores = [];
        }
    }
}
