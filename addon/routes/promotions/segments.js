import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PromotionsSegmentsRoute extends Route {
    @service store;
    @service storefront;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    queryParams = {
        query: { refreshModel: true },
        page: { refreshModel: true },
    };

    beforeModel() {
        if (this.abilities.cannot('storefront list customer-segment')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model(params) {
        return this.store.query('customer-segment', { ...params, owner: this.storefront.getActiveStore('id'), sort: '-created_at' });
    }
}
