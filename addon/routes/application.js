import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ApplicationRoute extends Route {
    @service fetch;
    @service store;
    @service loader;
    @service currentUser;
    @service modalsManager;
    // @service theme;
    @service storefront;

    @action loading(transition) {
        this.loader.showOnInitialTransition(transition, 'section.next-view-section', 'Loading storefront...');
    }

    @action willTransition() {
        this.modalsManager.done();
    }

    beforeModel() {
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

    afterModel(model) {
        if (model.length) {
            // this.storefront.listenForIncomingOrders();
        }
    }

    @action disableSandbox() {
        this.currentUser.setOption('sandbox', false);
        // this.theme.setEnvironment();
    }
}
