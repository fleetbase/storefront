import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class SettingsIndexRoute extends Route {
    @service store;
    @service currentUser;
    @service storefront;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    beforeModel() {
        if (this.abilities.cannot('storefront view settings')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }
    }

    model() {
        return this.store.peekRecord('store', this.currentUser.getOption('activeStorefront'));
    }

    afterModel(model) {
        model?.loadFiles();
    }
}
