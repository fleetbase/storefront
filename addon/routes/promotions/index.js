import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PromotionsIndexRoute extends Route {
    @service store;
    @service storefront;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    queryParams = {
        query: { refreshModel: true },
        status: { refreshModel: true },
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
        const query = { ...params };
        delete query.view;

        return this.store.query('promotion', { ...query, owner: this.storefront.getActiveStore('id'), sort: '-created_at' });
    }
}
