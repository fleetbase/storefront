import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OrdersIndexViewRoute extends Route {
    @service currentUser;
    @service notifications;
    @service store;
    @service socket;
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
        const order = this.store.queryRecord('order', {
            public_id,
            single: true,
            with: ['payload', 'driverAssigned', 'orderConfig', 'customer', 'facilitator', 'trackingStatuses', 'trackingNumber', 'purchaseRate', 'comments', 'files'],
        });

        return order;
    }
}
