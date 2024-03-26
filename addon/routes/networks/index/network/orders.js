import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkOrdersRoute extends Route {
    @service store;
    @service storefront;

    model(params) {
        return this.store.query('order', { ...params, storefront: this.storefront.getActiveStore('public_id') });
    }
}
