import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { alias } from '@ember/object/computed';

/**
 * Settings are one page with a section rail: the General page carries its sections as
 * anchors, the other pages keep their routes so deep links still work.
 */
export default class SettingsController extends Controller {
    @service intl;
    @service hostRouter;
    @service storefront;
    @service notifications;
    @alias('storefront.activeStore') activeStore;

    get currentRouteName() {
        return this.hostRouter.currentRouteName ?? '';
    }

    get isGeneralRoute() {
        return this.currentRouteName.endsWith('settings.index');
    }

    get railItems() {
        const routeItem = (id, label, icon, route, extra = {}) => ({
            id,
            label,
            icon,
            route,
            isActive: this.currentRouteName.endsWith(route),
            ...extra,
        });
        const sectionItem = (id, label, icon) => ({
            id,
            label,
            icon,
            section: `settings-${id}`,
            isActive: this.isGeneralRoute && this.activeSection === id,
        });

        return [
            sectionItem('general', this.intl.t('storefront.common.general'), 'cog'),
            sectionItem('branding', this.intl.t('storefront.settings.sections.branding'), 'image'),
            routeItem('locations', this.intl.t('storefront.common.location'), 'map-marker-alt', 'settings.locations'),
            sectionItem('checkout', this.intl.t('storefront.settings.sections.checkout-rules'), 'cart-shopping'),
            routeItem('gateways', this.intl.t('storefront.common.gateways'), 'cash-register', 'settings.gateways'),
            routeItem('notifications', this.intl.t('storefront.common.notification'), 'bell-concierge', 'settings.notifications'),
            sectionItem('alerts', this.intl.t('storefront.common.alerts'), 'bell'),
            routeItem('api', this.intl.t('storefront.settings.sections.api-keys'), 'code', 'settings.api'),
        ];
    }

    activeSection = 'general';

    @action openItem(item) {
        if (item.route) {
            return this.hostRouter.transitionTo(`console.storefront.${item.route}`);
        }

        this.activeSection = item.id;

        const scrollToSection = () => {
            const element = document.getElementById(item.section);
            element?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
        };

        if (this.isGeneralRoute) {
            scrollToSection();
            return;
        }

        return Promise.resolve(this.hostRouter.transitionTo('console.storefront.settings.index')).then(() => setTimeout(scrollToSection, 50));
    }

    @action async copyStoreKey() {
        const key = this.activeStore?.key;

        if (!key) {
            return;
        }

        try {
            await navigator.clipboard.writeText(key);
            this.notifications.success(this.intl.t('storefront.settings.shell.store-key-copied'));
        } catch {
            this.notifications.warning(key);
        }
    }
}
