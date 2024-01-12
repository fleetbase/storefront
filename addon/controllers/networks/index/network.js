import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class NetworksIndexNetworkController extends BaseController {
    @service modalsManager;

    @action transitionBack({ closeOverlay }) {
        if (this.model.hasDirtyAttributes) {
            // warn user about unsaved changes
            return this.modalsManager.confirm({
                title: this.intl.t('storefront.controllers.networks.index.network.title'),
                body: this.intl.t('storefront.controllers.networks.index.network.body'),
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
