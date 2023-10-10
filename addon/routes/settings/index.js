import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class SettingsIndexRoute extends Route {
    @service store;
    @service currentUser;
    @service storefront;

    model() {
        return this.store.peekRecord('store', this.currentUser.getOption('activeStorefront'));
    }

    afterModel(model) {
        model?.loadFiles();
    }
}
