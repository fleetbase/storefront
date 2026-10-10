import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkOrdersRoute extends Route {
    @service fetch;
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        status: { refreshModel: true },
        view: { refreshModel: false },
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
        const { view, ...rest } = params;

        return Object.entries({ ...rest, network: this.modelFor('networks.index.network').public_id }).reduce((queryParams, [key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                queryParams[key] = value;
            }

            return queryParams;
        }, {});
    }
}
