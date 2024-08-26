import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class CustomersIndexViewRoute extends Route {
    @service store;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    queryParams = {
        view: { refreshModel: false },
    };

    beforeModel() {
        if (this.abilities.cannot('storefront view customer')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model({ public_id }) {
        return this.store.findRecord('contact', public_id);
    }
}
