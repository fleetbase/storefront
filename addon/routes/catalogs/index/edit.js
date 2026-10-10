import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { hash } from 'rsvp';

/**
 * The catalog editor: a catalog edited as a container of categories, products, hours and
 * the stores and trucks that serve it.
 */
export default class CatalogsIndexEditRoute extends Route {
    @service store;
    @service storefront;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    beforeModel() {
        if (this.abilities.cannot('storefront view catalog')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront.catalogs');
        }
    }

    model({ public_id }) {
        const storeId = this.storefront.getActiveStore('id');

        return hash({
            catalog: this.store.findRecord('catalog', public_id, { reload: true }),
            products: this.store.query('product', { limit: -1, store_uuid: storeId }).catch(() => []),
            stores: this.store.query('store', { limit: 300 }).catch(() => []),
            trucks: this.store.query('food-truck', { store_uuid: storeId, limit: -1 }).catch(() => []),
        });
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        controller.catalog = model.catalog;
        controller.products = model.products?.toArray?.() ?? Array.from(model.products ?? []);
        controller.stores = model.stores?.toArray?.() ?? Array.from(model.stores ?? []);
        controller.trucks = model.trucks?.toArray?.() ?? Array.from(model.trucks ?? []);
        controller.selectedCategory = model.catalog.categories?.firstObject ?? null;
        controller.snapshot();
    }

    resetController(controller, isExiting) {
        if (isExiting) {
            controller.selectedCategory = null;
            controller.isPickerOpen = false;
            controller.pickerSelection = [];
        }
    }
}
