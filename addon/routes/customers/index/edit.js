import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class CustomersIndexEditRoute extends Route {
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    beforeModel() {
        if (this.abilities.cannot('storefront update customer')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }
}
