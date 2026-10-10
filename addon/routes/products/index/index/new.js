import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * Create a product from "All products": the form's category select decides where it lands.
 */
export default class ProductsIndexIndexNewRoute extends Route {
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    @action didTransition() {
        if (this.controller) {
            this.controller.reset();
        }
    }

    beforeModel() {
        if (this.abilities.cannot('storefront create product')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }
}
