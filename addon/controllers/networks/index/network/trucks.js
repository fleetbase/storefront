import FoodTrucksIndexController from '@fleetbase/storefront-engine/controllers/food-trucks/index';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Trucks in the network context: the member stores' trucks plus the trucks the network runs
 * itself. A new truck here is owned by the network.
 */
export default class NetworksIndexNetworkTrucksController extends FoodTrucksIndexController {
    queryParams = [{ query: 't_query' }];
    @tracked network;

    get newTruckAttributes() {
        return { network_uuid: this.network?.id, status: 'active' };
    }

    @action viewOrder(order) {
        return this.hostRouter.transitionTo('console.storefront.networks.index.network.orders', this.network.public_id, { queryParams: { o_query: order.public_id } });
    }
}
