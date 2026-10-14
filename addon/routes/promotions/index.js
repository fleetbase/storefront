import StorefrontListRoute from '@fleetbase/storefront-engine/utils/list-route';
import { inject as service } from '@ember/service';

export default class PromotionsIndexRoute extends StorefrontListRoute {
    @service store;
    @service storefront;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    queryParams = {
        query: { refreshModel: true },
        // Filtered in the controller: the tabs switch without a reload.
        status: { refreshModel: false },
        page: { refreshModel: true },
        view: { refreshModel: false },
    };

    beforeModel() {
        if (this.abilities.cannot('storefront list promotion')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model(params) {
        // `view` is a UI-only query param (cards or table).
        const query = { ...params, limit: 100 };
        delete query.view;
        delete query.status;

        return this.store.query('promotion', { ...query, owner: this.storefront.getContextOwner('id'), sort: '-created_at' });
    }
}
