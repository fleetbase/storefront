import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ProductsIndexCategoryEditRoute extends Route {
    @service store;
    templateName = 'products.index.category.new';

    model({ public_id }) {
        return this.store.queryRecord('product', {
            public_id,
            single: true,
            with: ['addonCategories', 'variants', 'files', 'hours'],
        });
    }
}
