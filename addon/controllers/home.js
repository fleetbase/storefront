import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import storeOpenState from '../utils/store-open-state';

export default class HomeController extends Controller {
    @service storefrontDashboard;
    @service storefront;
    @service hostRouter;
    @service intl;

    get activeStore() {
        return this.storefront.activeStore;
    }

    /**
     * Every location's hours together: the store is open when any location is.
     */
    get hours() {
        const locations = this.model?.toArray?.() ?? Array.from(this.model ?? []);

        return locations.flatMap((location) => {
            const hours = get(location, 'hours');

            return hours?.toArray?.() ?? Array.from(hours ?? []);
        });
    }

    get openState() {
        return storeOpenState(this.hours, { timezone: get(this.activeStore, 'timezone') });
    }

    get openStateLabel() {
        const state = this.openState;

        if (!state.hasHours) {
            return this.intl.t('storefront.dashboard.open-state.no-hours');
        }

        if (state.isOpen) {
            return this.intl.t('storefront.dashboard.open-state.open', { time: state.closesAt });
        }

        if (state.opensDay) {
            return this.intl.t('storefront.dashboard.open-state.closed-until-day', { day: this.intl.t(`storefront.dashboard.open-state.days.${state.opensDay}`), time: state.opensAt });
        }

        if (state.opensAt) {
            return this.intl.t('storefront.dashboard.open-state.closed-until', { time: state.opensAt });
        }

        return this.intl.t('storefront.dashboard.open-state.closed');
    }

    @action newOrder() {
        return this.hostRouter.transitionTo('console.storefront.orders.index.new');
    }
}
