import StorefrontListRoute from '@fleetbase/storefront-engine/utils/list-route';
import { inject as service } from '@ember/service';

export default class SettingsGatewaysRoute extends StorefrontListRoute {
    @service store;
    @service storefront;

    queryParams = {
        query: { refreshModel: true },
    };

    model(params) {
        return this.store.query('gateway', { ...params, owner_uuid: this.storefront?.activeStore?.id });
    }
}
