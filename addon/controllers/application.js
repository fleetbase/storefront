import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { alias } from '@ember/object/computed';

export default class ApplicationController extends Controller {
    @service storefront;
    @service hostRouter;
    @service loader;
    @alias('storefront.activeStore') activeStore;

    @action createNewStorefront() {
        return this.storefront.createNewStorefront({
            onSuccess: () => {
                const loader = this.loader.show({ loadingMessage: `Switching to newly created store...` });

                this.hostRouter.refresh().then(() => {
                    this.notifyPropertyChange('activeStore');
                    this.loader.removeLoader(loader);
                });
            },
        });
    }

    @action switchActiveStore(store) {
        const loader = this.loader.show({ loadingMessage: `Switching Storefront to ${store.name}...` });
        this.storefront.setActiveStorefront(store);
        this.hostRouter.refresh().then(() => {
            this.notifyPropertyChange('activeStore');
            this.loader.removeLoader(loader);
        });
    }
}
