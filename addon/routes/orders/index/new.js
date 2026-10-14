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
        // Preselects the customer (`?for_customer=<public id or uuid>`); the list owns `customer`.
        forCustomer: { refreshModel: false },
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

        const customerId = transition?.to?.queryParams?.for_customer ?? controller.forCustomer;
        const customer = await this.findCustomer(customerId);
        if (customer) {
            // Through the action so the customer's default place becomes the dropoff and a quote runs.
            controller.selectCustomer(customer);
        }
    }

    /**
     * The customer endpoint has no single-record route, so a customer is resolved from the
     * store when already loaded, otherwise through the list endpoint by public id or uuid.
     */
    async findCustomer(id) {
        if (typeof id !== 'string' || !id) {
            return null;
        }

        const loaded = this.store.peekRecord('customer', id) ?? this.store.peekAll('customer').find((customer) => customer.public_id === id);
        if (loaded) {
            return loaded;
        }

        try {
            const results = await this.store.query('customer', { [id.includes('_') ? 'public_id' : 'uuid']: id, limit: 1 });
            return results.firstObject ?? results[0] ?? null;
        } catch {
            return null;
        }
    }
}
