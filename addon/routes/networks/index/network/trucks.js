import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkTrucksRoute extends Route {
    @service store;

    queryParams = {
        query: { refreshModel: true, as: 't_query' },
    };

    get network() {
        return this.modelFor('networks.index.network');
    }

    model(params = {}) {
        return this.store.query('food-truck', { ...params, network: this.network.id });
    }
}
