import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class CatalogsIndexRoute extends Route {
    @service store;
    @service storefront;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    beforeModel() {
        if (this.abilities.cannot('storefront list catalog')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model(params) {
        return this.store.query('catalog', { ...params, store_uuid: this.storefront.getActiveStore('id') });
    }
}
