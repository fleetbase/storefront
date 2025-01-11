import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ApplicationRoute extends Route {
    @service fetch;
    @service store;
    @service loader;
    @service currentUser;
    @service modalsManager;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;
    @service storefront;

    @action loading(transition) {
        this.loader.showOnInitialTransition(transition, 'section.next-view-section', { loadingMessage: 'Loading storefront...' });
    }

    @action error(error) {
        this.notifications.serverError(error);
    }

    @action willTransition() {
        this.modalsManager.done();
    }

    beforeModel() {
        if (this.abilities.cannot('storefront see extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }

        this.disableSandbox();
        return this.fetch.get('actions/store-count', {}, { namespace: 'storefront/int/v1' }).then(({ storeCount }) => {
            // if no store count prompt to create a store
            if (!storeCount) {
                return this.storefront.createFirstStore();
            }
        });
    }

    model() {
        return this.store.query('store', { limit: 300, sort: '-updated_at' });
    }

    afterModel() {
        this.storefront.listenForIncomingOrders();
    }

    disableSandbox() {
        this.currentUser.setOption('sandbox', false);
        // this.theme.setEnvironment();
    }
}
