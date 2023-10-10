import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ProductsIndexRoute extends Route {
    @service store;
    @service currentUser;

    @action willTransition() {
        this.controller.category = null;
    }

    model(params = {}) {
        return this.store.query('category', {
            for: 'storefront_product',
            owner_uuid: this.currentUser.getOption('activeStorefront'),
            limit: -1,
            ...params,
        });
    }
}
