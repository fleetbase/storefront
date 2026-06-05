import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

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
        const order = this.fetch.get(
            `orders/${public_id}`,
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

        return order;
    }

    async setupController(controller, model) {
        super.setupController(...arguments);

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
