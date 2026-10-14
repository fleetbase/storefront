import StorefrontListRoute from '@fleetbase/storefront-engine/utils/list-route';
import { inject as service } from '@ember/service';

export default class SettingsNotificationsRoute extends StorefrontListRoute {
    @service store;
    @service storefront;

    queryParams = {
        query: { refreshModel: true },
    };

    model(params) {
        return this.store.query('notification-channel', { ...params, owner_uuid: this.storefront?.activeStore?.id });
    }
}
