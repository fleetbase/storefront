import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class HomeRoute extends Route {
    @service store;
    @service storefront;

    /**
     * The store's locations with their hours drive the open / closed state in the header;
     * the dashboard widgets load their own data.
     */
    model() {
        const storeId = this.storefront?.activeStore?.id;

        if (!storeId) {
            return [];
        }

        return this.store.query('store-location', { store: storeId, with: ['hours'] }).catch(() => []);
    }
}
