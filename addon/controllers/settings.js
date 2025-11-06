import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class SettingsController extends Controller {
    @service intl;

    get tabs() {
        return [
            {
                route: 'settings.index',
                label: this.intl.t('storefront.common.general'),
                icon: 'cog',
            },
            {
                route: 'settings.locations',
                label: this.intl.t('storefront.common.location'),
                icon: 'map-marker-alt',
            },
            {
                route: 'settings.gateways',
                label: this.intl.t('storefront.common.gateways'),
                icon: 'cash-register',
            },
            {
                route: 'settings.api',
                label: this.intl.t('storefront.common.api'),
                icon: 'code',
            },
            {
                route: 'settings.notifications',
                label: this.intl.t('storefront.common.notification'),
                icon: 'bell-concierge',
            },
        ];
    }
}
