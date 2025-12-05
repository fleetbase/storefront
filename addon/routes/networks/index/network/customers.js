import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkCustomersRoute extends Route {
    @service store;
    @service storefront;

    queryParams = {
        page: { refreshModel: true, as: 'n_page' },
        limit: { refreshModel: true, as: 'n_limit' },
        sort: { refreshModel: true, as: 'n_sort' },
        query: { refreshModel: true, as: 'n_query' },
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
        return this.store.query('customer', { ...params, storefront: this.storefront.getActiveStore('public_id') });
    }
}
