import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

// Orders whose details this route has fetched; a record handed over by a list is not among them.
const hydrated = new WeakSet();

export default class OrdersIndexViewRoute extends Route {
    @service notifications;
    @service storefront;
    @service fetch;
    @service intl;
    @service abilities;
    @service hostRouter;

    @action error(error) {
        this.notifications.serverError(error);
    }

    beforeModel() {
        if (this.abilities.cannot('storefront view order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model({ public_id }) {
        return this.loadOrder(public_id);
    }

    async loadOrder(publicId) {
        const order = await this.fetch.get(
            `orders/${publicId}`,
            {
                storefront: this.storefront.getActiveStore('public_id'),
                with: [
                    'payload',
                    'payload.pickup',
                    'payload.dropoff',
                    'payload.return',
                    'payload.waypoints',
                    'payload.entities',
                    'driverAssigned',
                    'orderConfig',
                    'customer',
                    'transaction',
                    'trackingStatuses',
                    'trackingNumber',
                    'purchaseRate',
                    'purchaseRate.serviceQuote',
                    'purchaseRate.serviceQuote.items',
                    'comments',
                    'files',
                ],
            },
            {
                namespace: 'storefront/int/v1',
                normalizeToEmberData: true,
                normalizeModelType: 'order',
            }
        );

        hydrated.add(order);
        return order;
    }

    async setupController(controller, model) {
        super.setupController(...arguments);

        // Opening an order from a list passes the list's record, which skips `model()`; the list
        // payload carries no items, waypoints or tracking. Fetching the details pushes them into
        // the same record, so the panel fills in place.
        if (model?.public_id && !hydrated.has(model)) {
            try {
                await this.loadOrder(model.public_id);
            } catch (error) {
                this.notifications.serverError(error);
            }
        }

        await Promise.allSettled([
            model.loadPayload?.(),
            model.loadCustomer?.(),
            model.loadDriver?.(),
            model.loadOrderConfig?.(),
            model.loadTrackingNumber?.(),
            model.loadComments?.(),
            model.loadFiles?.(),
        ]);

        await model.loadTrackingActivity?.();
    }
}
