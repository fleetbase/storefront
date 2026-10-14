import Route from '@ember/routing/route';

/**
 * The network's promotions list renders the store promotions template, so it loads with the
 * store promotions skeleton too.
 */
export default class NetworksIndexNetworkPromotionsIndexLoadingRoute extends Route {
    templateName = 'promotions/index-loading';
}
