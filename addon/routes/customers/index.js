import StorefrontListRoute from '@fleetbase/storefront-engine/utils/list-route';
import { inject as service } from '@ember/service';
import queryCustomers from '@fleetbase/storefront-engine/utils/query-customers';

export default class CustomersIndexRoute extends StorefrontListRoute {
    @service store;
    @service fetch;
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
        phone: { refreshModel: true },
        email: { refreshModel: true },
        address: { refreshModel: true },
        public_id: { refreshModel: true },
        internal_id: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    beforeModel() {
        if (this.abilities.cannot('storefront list customer')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model(params) {
        return queryCustomers(this, { ...params, storefront: this.storefront.getActiveStore('public_id') });
    }
}
