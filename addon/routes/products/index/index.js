import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ProductsIndexIndexRoute extends Route {
    @service store;
    @service currentUser;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        public_id: { refreshModel: true },
        sku: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    model(params) {
        return this.store.query('product', { store_uuid: this.currentUser.getOption('activeStorefront'), ...params });
    }
}
