import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkStoresRoute extends Route {
    @service store;

    queryParams = {
        category: { refreshModel: true },
        storeQuery: { refreshModel: true },
    };

    get network() {
        return this.modelFor('networks.index.network');
    }

    model(params = {}) {
        return this.store.query('store', { network: this.network.id, with_category: 1, ...params });
    }

    async setupController(controller, model) {
        super.setupController(controller, model);

        // set the network to controller
        controller.network = this.network;

        // set the cateogry if set
        const queryParams = this.paramsFor(this.routeName);
        if (queryParams.category) {
            const category = this.store.peekRecord('category', queryParams.category);
            if (category) {
                controller.selectCategory(category);
            }
        }
    }
}
