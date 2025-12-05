import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ProductsIndexRoute extends Route {
    @service store;
    @service currentUser;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    @action willTransition() {
        this.controller.category = null;
    }

    beforeModel() {
        if (this.abilities.cannot('storefront list product')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model(params = {}) {
        return this.store.query('category', {
            for: 'storefront_product',
            owner_uuid: this.currentUser.getOption('activeStorefront'),
            limit: -1,
            ...params,
        });
    }
}
