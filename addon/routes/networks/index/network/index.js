import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkIndexRoute extends Route {
    @service store;

    model() {
        return this.modelFor('networks.index.network');
    }

    async setupController(controller) {
        super.setupController(...arguments);
        controller.orderConfigs = await this.store.findAll('order-config');
    }
}
