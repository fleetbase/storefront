import Route from '@ember/routing/route';

/**
 * The network's promotions hub pages (campaigns, segments, redemptions) render the store
 * templates, so they load with the store hub skeleton too.
 */
export default class NetworksIndexNetworkPromotionsLoadingRoute extends Route {
    templateName = 'promotions/loading';
}
