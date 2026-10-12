import StorefrontListRoute from '@fleetbase/storefront-engine/utils/list-route';
import { inject as service } from '@ember/service';
import queryCustomers from '@fleetbase/storefront-engine/utils/query-customers';

export default class NetworksIndexNetworkCustomersRoute extends StorefrontListRoute {
    @service store;
    @service fetch;

    queryParams = {
        page: { refreshModel: true, as: 'c_page' },
        limit: { refreshModel: true, as: 'c_limit' },
        sort: { refreshModel: true, as: 'c_sort' },
        query: { refreshModel: true, as: 'c_query' },
        status: { refreshModel: true, as: 'n_status' },
        phone: { refreshModel: true },
        email: { refreshModel: true },
        address: { refreshModel: true },
        public_id: { refreshModel: true },
        internal_id: { refreshModel: true },
        created_at: { refreshModel: true, as: 'n_created_at' },
        updated_at: { refreshModel: true, as: 'n_updated_at' },
    };

    model(params) {
        return queryCustomers(this, { ...params, network: this.modelFor('networks.index.network').public_id });
    }
}
