import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { alias } from '@ember/object/computed';

/**
 * Settings → Push notifications: the active store's push app (see Storefront::PushAppSettings).
 */
export default class SettingsNotificationsController extends Controller {
    @service storefront;
    @alias('storefront.activeStore') activeStore;
}
