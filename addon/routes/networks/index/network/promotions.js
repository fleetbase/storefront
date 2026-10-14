import PromotionsRoute from '@fleetbase/storefront-engine/routes/promotions';

/**
 * The promotions hub inside a network: the same shell and tabs, scoped to records the
 * network owns. The parent network route has already made the network the active context.
 */
export default class NetworksIndexNetworkPromotionsRoute extends PromotionsRoute {
    templateName = 'promotions';
    controllerName = 'promotions';
}
