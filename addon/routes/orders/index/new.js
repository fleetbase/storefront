import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { hash } from 'rsvp';

/**
 * A console order follows the same flow as an app order: products from the store's catalog,
 * a delivery quote, promotions and totals from the API.
 */
export default class OrdersIndexNewRoute extends Route {
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;
    @service store;
    @service storefront;

    queryParams = {
        // The orders list already owns the `customer` URL key.
        customer: { refreshModel: false, as: 'for_customer' },
    };

    beforeModel() {
        if (this.abilities.cannot('storefront create order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model() {
        const activeStore = this.storefront.activeStore;
        const storeId = activeStore?.id;

        return hash({
            activeStore,
            locations: this.store.query('store-location', { store: storeId, limit: -1 }).catch(() => []),
            products: this.store.query('product', { store_uuid: storeId, limit: -1, status: 'published' }).catch(() => []),
        });
    }

    async setupController(controller, model, transition) {
        super.setupController(...arguments);
        controller.reset();
        controller.activeStore = model.activeStore;
        controller.locations = model.locations?.toArray?.() ?? Array.from(model.locations ?? []);
        controller.products = model.products?.toArray?.() ?? Array.from(model.products ?? []);
        controller.pickupLocation = controller.locations[0] ?? null;

        const customerId = transition?.to?.queryParams?.for_customer ?? transition?.to?.queryParams?.customer ?? controller.customer;

        if (typeof customerId === 'string' && customerId) {
            try {
                controller.selectedCustomer = await this.store.findRecord('customer', customerId);
            } catch {
                controller.selectedCustomer = null;
            }
        }
    }
}
