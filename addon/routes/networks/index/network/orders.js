import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkOrdersRoute extends Route {
    @service fetch;
    @service store;

    queryParams = {
        page: { refreshModel: true, as: 'o_page' },
        limit: { refreshModel: true, as: 'o_limit' },
        sort: { refreshModel: true, as: 'o_sort' },
        query: { refreshModel: true, as: 'o_query' },
        status: { refreshModel: true, as: 'o_status' },
        view: { refreshModel: false, as: 'o_view' },
    };

    async model(params) {
        const response = await this.fetch.get('orders', this.buildQueryParams(params), { namespace: 'storefront/int/v1' });
        const orders = this.fetch.normalizeModel(response, 'orders');

        orders.meta = response.meta;

        return orders;
    }

    async setupController(controller, model) {
        super.setupController(controller, model);
        controller.network = this.modelFor('networks.index.network');

        try {
            const stores = await this.store.query('store', { network: controller.network.id });
            controller.stores = stores?.toArray?.() ?? Array.from(stores ?? []);
        } catch {
            controller.stores = [];
        }
    }

    buildQueryParams(params = {}) {
        // `view` is a UI-only query param (grouped or flat).
        const rest = { ...params };
        delete rest.view;

        return Object.entries({ ...rest, network: this.modelFor('networks.index.network').public_id }).reduce((queryParams, [key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                queryParams[key] = value;
            }

            return queryParams;
        }, {});
    }
}
