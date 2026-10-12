import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkRoute extends Route {
    @service store;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;
    @service storefront;

    beforeModel() {
        if (this.abilities.cannot('storefront view network')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model({ public_id }) {
        return this.store.findRecord('network', public_id);
    }

    /**
     * A URL under a network scopes the console to that network; moving between two
     * networks re-runs this hook without deactivating the route.
     */
    afterModel(network) {
        this.storefront.setActiveNetwork(network);
    }

    deactivate() {
        super.deactivate(...arguments);
        this.storefront.clearActiveNetwork();
    }
}
