import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OrdersIndexViewRoute extends Route {
    @service currentUser;
    @service notifications;
    @service store;
    @service socket;

    @action willTransition(transition) {
        const shouldReset = typeof transition.to.name === 'string' && !transition.to.name.includes('operations.orders');

        if (this.controller) {
            this.controller.removeRoutingControlPreview();

            if (shouldReset) {
                this.controller.resetView();
            }
        }
    }

    @action error(error) {
        this.notifications.serverError(error);
    }

    model({ public_id }) {
        const order = this.store.queryRecord('order', {
            public_id,
            single: true,
            with: ['payload', 'driverAssigned', 'orderConfig', 'customer', 'facilitator', 'trackingStatuses', 'trackingNumber', 'purchaseRate', 'comments', 'files'],
        });

        return order;
    }
}
