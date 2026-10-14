import StorefrontListRoute from '@fleetbase/storefront-engine/utils/list-route';
import { inject as service } from '@ember/service';

export default class PromotionsSegmentsRoute extends StorefrontListRoute {
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
        return this.store.query('customer-segment', { ...params, owner: this.storefront.getContextOwner('id'), sort: '-created_at' });
    }
}
