import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkSettingsRoute extends Route {
    @service store;
    @service abilities;

    model() {
        return this.modelFor('networks.index.network');
    }

    /**
     * The network record loads without its gateways: only the networks list asks for them, and
     * entering a network skips the list. So a refresh showed none of the ones saved; load what
     * this network owns each time the page opens. The push app section loads its own.
     */
    async afterModel(network) {
        const gateways = this.abilities.can('storefront list gateway') ? await this.store.query('gateway', { owner_uuid: network.id, limit: -1 }) : [];

        network.setProperties({ gateways: gateways.toArray?.() ?? gateways });
    }

    async setupController(controller) {
        super.setupController(...arguments);
        controller.activeSection = 'general';
        controller.snapshotOptions();
        controller.orderConfigs = await this.store.findAll('order-config');
    }
}
