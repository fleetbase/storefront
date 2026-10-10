import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { hash } from 'rsvp';

/**
 * The network overview: members, invitations, the last seven days of orders and revenue.
 */
export default class NetworksIndexNetworkIndexRoute extends Route {
    @service fetch;
    @service notifications;

    get network() {
        return this.modelFor('networks.index.network');
    }

    model() {
        const { network } = this;

        return hash({
            network,
            overview: this.fetch.get(`networks/${network.id}/overview`, {}, { namespace: 'storefront/int/v1' }).catch(() => null),
            invitations: this.fetch.get(`networks/${network.id}/invitations`, {}, { namespace: 'storefront/int/v1' }).catch(() => []),
        });
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        controller.network = model.network;
        controller.overview = model.overview;
        controller.invitations = model.invitations ?? [];
    }
}
