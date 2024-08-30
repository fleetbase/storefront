import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { filterHasManyForNewRecords } from '../../../../serializers/product';

export default class ProductsIndexCategoryEditRoute extends Route {
    @service store;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;
    templateName = 'products.index.category.new';

    beforeModel() {
        if (this.abilities.cannot('storefront update product')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model({ public_id }) {
        return this.store.queryRecord('product', {
            public_id,
            single: true,
            with: ['addonCategories', 'variants', 'files', 'hours'],
        });
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        controller.product = filterHasManyForNewRecords(model, ['variants', 'addon_categories', 'hours']);
    }
}
