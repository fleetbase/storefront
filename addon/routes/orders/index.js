import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';
import isNestedRouteTransition from '@fleetbase/ember-core/utils/is-nested-route-transition';

export default class OrdersIndexRoute extends Route {
    @service store;
    @service storefront;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        status: { refreshModel: true },
        public_id: { refreshModel: true },
        internal_id: { refreshModel: true },
        payload: { refreshModel: true },
        tracking: { refreshModel: true },
        facilitator: { refreshModel: true },
        driver: { refreshModel: true },
        customer: { refreshModel: true },
        pickup: { refreshModel: true },
        dropoff: { refreshModel: true },
        after: { refreshModel: true },
        before: { refreshModel: true },
    };

    @action willTransition(transition) {
        if (isNestedRouteTransition(transition)) {
            set(this.queryParams, 'page.refreshModel', false);
            set(this.queryParams, 'sort.refreshModel', false);
        }
    }

    beforeModel() {
        if (this.abilities.cannot('storefront list order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model(params) {
        return this.store.query('order', { ...params, storefront: this.storefront.getActiveStore('public_id') });
    }
}
