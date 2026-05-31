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
            },
            {
                namespace: 'storefront/int/v1',
                normalizeToEmberData: true,
                normalizeModelType: 'order',
            }
        );

        return order;
    }
}
