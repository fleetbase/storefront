import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkSettingsRoute extends Route {
    @service store;

    model() {
        return this.modelFor('networks.index.network');
    }

    async setupController(controller) {
        super.setupController(...arguments);
        controller.activeSection = 'general';
        controller.snapshotOptions();
        controller.orderConfigs = await this.store.findAll('order-config');
    }
}
