import OrdersIndexViewRoute from '../../../../orders/index/view';

/**
 * An order opened from the network's orders: the store's order panel, at its own URL over the
 * network's list.
 */
export default class NetworksIndexNetworkOrdersViewRoute extends OrdersIndexViewRoute {
    templateName = 'orders/index/view';
}
