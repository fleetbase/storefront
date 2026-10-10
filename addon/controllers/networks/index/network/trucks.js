import FoodTrucksIndexController from '@fleetbase/storefront-engine/controllers/food-trucks/index';

/**
 * Trucks run by the stores of the active network. Creating a truck still happens from its store.
 */
export default class NetworksIndexNetworkTrucksController extends FoodTrucksIndexController {
    queryParams = [{ query: 't_query' }];
}
