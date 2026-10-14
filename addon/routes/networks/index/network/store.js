import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * A member store seen from its network: its numbers, orders and customers inside the network.
 */
export default class NetworksIndexNetworkStoreRoute extends Route {
    @service store;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    beforeModel() {
        if (this.abilities.cannot('storefront view store')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model({ store_id }) {
        return this.store.findRecord('store', store_id);
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        // Another store opens on its orders, not on the tab the last one was left on.
        controller.tab = 'orders';
        controller.load.perform(model);
    }
}
