import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class SettingsNotificationsRoute extends Route {
    @service store;
    @service storefront;

    model() {
        return this.store.query('notification-channel', { owner_uuid: this.storefront?.activeStore?.id });
    }
}
