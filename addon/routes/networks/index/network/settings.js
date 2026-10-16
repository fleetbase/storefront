import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkSettingsRoute extends Route {
    @service store;
    @service abilities;

    model() {
        return this.modelFor('networks.index.network');
    }

    /**
     * The network record loads without its gateways and notification channels: only the networks
     * list asks for them, and entering a network skips the list. So a refresh showed none of the
     * ones saved; load what this network owns each time the page opens.
     */
    async afterModel(network) {
        const ownedBy = { owner_uuid: network.id, limit: -1 };
        const [gateways, channels] = await Promise.all([
            this.abilities.can('storefront list gateway') ? this.store.query('gateway', ownedBy) : [],
            this.abilities.can('storefront list notification-channel') ? this.store.query('notification-channel', ownedBy) : [],
        ]);

        network.setProperties({ gateways: gateways.toArray?.() ?? gateways, notification_channels: channels.toArray?.() ?? channels });
    }

    async setupController(controller) {
        super.setupController(...arguments);
        controller.activeSection = 'general';
        controller.snapshotOptions();
        controller.orderConfigs = await this.store.findAll('order-config');
    }
}
