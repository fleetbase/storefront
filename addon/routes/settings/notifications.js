import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class SettingsNotificationsRoute extends Route {
    @service store;
    @service storefront;

    queryParams = {
        query: { refreshModel: true },
    };

    model(params) {
        return this.store.query('notification-channel', { ...params, owner_uuid: this.storefront?.activeStore?.id });
    }
}
