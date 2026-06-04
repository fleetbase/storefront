import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Represents a component for managing addon categories and individual addons.
 * Allows for the creation, deletion, and editing of addon categories, as well as adding and removing addons within those categories.
 * This component uses various Ember services like store, internationalization, currentUser, modalsManager, and notifications for its operations.
 */
export default class ModalsManageAddonsComponent extends Component {
    @tracked addonManagement;

    @action setAddonManagement(addonManagement) {
        this.addonManagement = addonManagement;
    }

    /**
     * Saves changes to all the categories.
     * Displays loading modal during the operation and handles errors.
     */
    @task *saveChanges() {
        if (this.addonManagement) {
            return yield this.addonManagement.saveChanges.perform();
        }
    }
}
