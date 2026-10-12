import StorefrontListRoute from '@fleetbase/storefront-engine/utils/list-route';
import { inject as service } from '@ember/service';

const LIST_ROUTE = 'console.storefront.networks.index.index';

export default class NetworksIndexRoute extends StorefrontListRoute {
    @service store;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    constructor() {
        super(...arguments);
        this.hostRouter.on('routeDidChange', this, this.loadSkippedList);
    }

    willDestroy() {
        this.hostRouter.off('routeDidChange', this, this.loadSkippedList);
        super.willDestroy(...arguments);
    }

    beforeModel() {
        if (this.abilities.cannot('storefront list network')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    /**
     * The networks list is only shown on its own page. Entering a network passes through this
     * route too, so it skips the list (every network with its gateways and channels) then, which
     * made switching into a network slow.
     */
    model(params, transition) {
        if (transition?.to?.name && transition.to.name !== LIST_ROUTE) {
            this.skippedList = true;
            return [];
        }

        this.skippedList = false;
        return this.store.query('network', { with_gateways: 1, with_notification_channels: 1, ...params });
    }

    /** Arriving on the list from inside a network: this route stayed active, so load the list now. */
    loadSkippedList(transition) {
        if (this.skippedList && transition?.to?.name === LIST_ROUTE) {
            this.skippedList = false;
            this.refresh();
        }
    }
}
