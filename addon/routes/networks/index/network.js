import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class NetworksIndexNetworkRoute extends Route {
    @service store;

    model({ public_id }) {
        return this.store.findRecord('network', public_id);
    }
}
