import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { set } from '@ember/object';

export default class NetworksIndexNetworkStoresRoute extends Route {
    @service store;
    @service hostRouter;
    @service fetch;

    queryParams = {
        category: { refreshModel: true },
        storeQuery: { refreshModel: true },
    };

    get network() {
        return this.modelFor('networks.index.network');
    }

    model(params = {}) {
        return this.store.query('store', { network: this.network.id, with_category: 1, ...params });
    }

    async setupController(controller, model) {
        super.setupController(controller, model);

        // set the network to controller
        controller.network = this.network;
        controller.invitations = (await this.network.loadInvitations().catch(() => [])) ?? [];

        // The overview endpoint already computes each member's last-7-day orders and share.
        const overview = await this.fetch.get(`networks/${this.network.id}/overview`, {}, { namespace: 'storefront/int/v1' }).catch(() => null);
        const statsByStore = new Map((overview?.members ?? overview?.store_stats ?? []).map((member) => [member.public_id, member]));
        const members = model?.toArray?.() ?? Array.from(model ?? []);
        members.forEach((store) => {
            const stats = statsByStore.get(store.public_id);
            set(store, 'orders_7d', stats?.orders_7d ?? 0);
            set(store, 'share', stats?.share ?? 0);
        });

        // set the cateogry if set
        const { category: categoryId } = this.paramsFor(this.routeName);
        if (categoryId) {
            controller.category = categoryId;
        }
    }
}
