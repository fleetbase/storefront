import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkOrdersRoute extends Route {
    @service fetch;

    async model(params) {
        const response = await this.fetch.get('orders', this.buildQueryParams(params), { namespace: 'storefront/int/v1' });
        const orders = this.fetch.normalizeModel(response, 'orders');

        orders.meta = response.meta;

        return orders;
    }

    buildQueryParams(params = {}) {
        return Object.entries({ ...params, network: this.modelFor('networks.index.network').public_id }).reduce((queryParams, [key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                queryParams[key] = value;
            }

            return queryParams;
        }, {});
    }
}
