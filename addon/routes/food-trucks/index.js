import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class FoodTrucksIndexRoute extends Route {
    @service store;
    @service storefront;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    beforeModel() {
        if (this.abilities.cannot('storefront list food-truck')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model(params) {
        return this.store.query('food-truck', { ...params, store_uuid: this.storefront.getActiveStore('id') });
    }
}
