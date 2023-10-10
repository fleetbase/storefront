import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class SettingsLocationsRoute extends Route {
    @service store;
    @service storefront;

    model() {
        return this.store.query('store-location', {
            store_uuid: this.storefront?.activeStore?.id,
            with: ['hours'],
        });
    }
}
