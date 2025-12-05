import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class NetworksIndexNetworkController extends BaseController {
    @service modalsManager;
    @service intl;

    get tabs() {
        return [
            {
                label: this.intl.t('storefront.networks.index.network.settings'),
                icon: 'cog',
                route: 'networks.index.network.index',
            },
            {
                label: this.intl.t('storefront.networks.index.network.stores.store'),
                icon: 'store',
                route: 'networks.index.network.stores',
            },
            {
                label: this.intl.t('storefront.networks.index.network.orders'),
                icon: 'file-invoice-dollar',
                route: 'networks.index.network.orders',
            },
            {
                label: this.intl.t('storefront.networks.index.network.customers'),
                icon: 'users',
                route: 'networks.index.network.customers',
            },
        ];
    }

    @action transitionBack({ closeOverlay }) {
        if (this.model.hasDirtyAttributes) {
            // warn user about unsaved changes
            return this.modalsManager.confirm({
                title: this.intl.t('storefront.controllers.networks.index.network.Network-changes-not-save'),
                body: this.intl.t('storefront.controllers.networks.index.network.going-back-will-rollback-all-unsaved-changes'),
                confirm: (modal) => {
                    modal.done();
                    return this.exit(closeOverlay);
                },
            });
        }

        return this.exit(closeOverlay);
    }

    @action exit(closeOverlay) {
        return closeOverlay(() => {
            return this.transitionToRoute('networks.index');
        });
    }
}
