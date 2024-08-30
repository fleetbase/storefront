import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';
import isNestedRouteTransition from '@fleetbase/ember-core/utils/is-nested-route-transition';

export default class ProductsIndexRoute extends Route {
    @service store;
    @service currentUser;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    @action willTransition(transition) {
        this.controller.category = null;

        if (isNestedRouteTransition(transition)) {
            set(this.queryParams, 'page.refreshModel', false);
            set(this.queryParams, 'sort.refreshModel', false);
        }
    }

    beforeModel() {
        if (this.abilities.cannot('storefront list product')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model(params = {}) {
        return this.store.query('category', {
            for: 'storefront_product',
            owner_uuid: this.currentUser.getOption('activeStorefront'),
            limit: -1,
            ...params,
        });
    }
}
