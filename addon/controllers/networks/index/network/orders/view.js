import OrdersIndexViewController from '../../../../orders/index/view';
import { action } from '@ember/object';

export default class NetworksIndexNetworkOrdersViewController extends OrdersIndexViewController {
    /** The order and the network's list behind it, in place: the panel stays open while they reload. */
    @action refreshOrder() {
        return this.hostRouter.refresh('console.storefront.networks.index.network.orders');
    }

    @action transitionBack() {
        return this.transitionToRoute('networks.index.network.orders');
    }
}
