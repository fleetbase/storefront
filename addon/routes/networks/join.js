import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * The landing for a network invitation link: review the network, pick which store joins, accept or decline.
 */
export default class NetworksJoinRoute extends Route {
    @service fetch;
    @service store;
    @service storefront;

    model({ uri }) {
        return this.fetch.get(`networks/join/${uri}`, {}, { namespace: 'storefront/int/v1' }).then((response) => ({ uri, ...response }));
    }

    setupController(controller) {
        super.setupController(...arguments);
        controller.stores = this.store.peekAll('store').toArray();
        controller.selectedStore = this.storefront.activeStore ?? controller.stores[0] ?? null;
        controller.done = false;
    }
}
