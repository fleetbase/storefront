import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { hash } from 'rsvp';

/**
 * The settings shell loads what the rail and the General page share: the store's
 * locations with their hours (for the rail count and the Hours summary) and its
 * gateways (for the rail count). The Locations page reads the same collection.
 */
export default class SettingsRoute extends Route {
    @service store;
    @service storefront;

    model() {
        const storeId = this.storefront?.activeStore?.id;

        if (!storeId) {
            return hash({ locations: [], gateways: [] });
        }

        return hash({
            locations: this.store.query('store-location', { store: storeId, with: ['hours'] }),
            gateways: this.store.query('gateway', { owner_uuid: storeId }),
        });
    }
}
