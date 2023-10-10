import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class NetworksIndexNetworkController extends Controller {
    @service modalsManager;

    @action transitionBack({ closeOverlay }) {
        if (this.model.hasDirtyAttributes) {
            // warn user about unsaved changes
            return this.modalsManager.confirm({
                title: 'Network changes not saved!',
                body: 'Going back will rollback all unsaved changes, are you sure you wish to continue?',
                confirm: (modal) => {
                    modal.done();
                    return this.exit(closeOverlay);
                },
            });
        }

        return this.exit(closeOverlay);
    }

    @action exit(closeOverlay) {
        return closeOverlay(() => {
            return this.transitionToRoute('networks.index');
        });
    }
}
