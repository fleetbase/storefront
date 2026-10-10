import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * The promotions hub: counts for every tab, loaded once for the shell.
 */
export default class PromotionsRoute extends Route {
    @service fetch;
    @service storefront;

    model() {
        return this.fetch.get('promotions/hub', { owner: this.storefront.getActiveStore('id') }, { namespace: 'storefront/int/v1' }).catch(() => null);
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        controller.hub = model;
    }
}
