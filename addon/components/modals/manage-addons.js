import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';
import { all } from 'rsvp';

/**
 * Represents a component for managing addon categories and individual addons.
 * Allows for the creation, deletion, and editing of addon categories, as well as adding and removing addons within those categories.
 * This component uses various Ember services like store, internationalization, currentUser, modalsManager, and notifications for its operations.
 */
export default class ModalsManageAddonsComponent extends Component {
    @service store;
    @service intl;
    @service currentUser;
    @service modalsManager;
    @service notifications;
    @tracked categories = [];
    @tracked options = {};
    @tracked activeStore;

    /**
     * Constructs the ModalsManageAddonsComponent instance with the given options.
     *
     * @param {Object} owner - The owner of the instance.
     * @param {Object} options - Configuration options for the component.
     */
    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.activeStore = options.store;
        this.getAddonCategories.perform();
    }

    /**
     * Creates a new addon associated with the provided category.
     * @param {Object} category - The category to which the new addon will be added.
     */
    @action createNewAddon(category) {
        const productAddon = this.store.createRecord('product-addon', { category_uuid: category.id });
        category.addons.pushObject(productAddon);
    }

    /**
     * Saves changes to all the categories.
     * Displays loading modal during the operation and handles errors.
     */
    @task *saveChanges() {
        this.modalsManager.startLoading();
        try {
            yield all(this.categories.map((_) => _.save()));
        } catch (error) {
            this.modalsManager.stopLoading();
            return this.notifications.serverError(error);
        }
        yield this.modalsManager.done();
        this.categories = [];
    }

    /**
     * Removes an addon from the specified category.
     * @param {Object} category - The category from which the addon will be removed.
     * @param {number} index - The index of the addon to remove.
     */
    @task *removeAddon(category, index) {
        const addon = category.addons.objectAt(index);
        category.addons.removeAt(index);
        yield addon.destroyRecord();
    }

    /**
     * Saves the provided addon category.
     * @param {Object} category - The addon category to save.
     */
    @task *saveAddonCategory(category) {
        yield category.save();
    }

    /**
     * Deletes an addon category at the specified index.
     * @param {number} index - The index of the category to delete.
     */
    @task *deleteAddonCategory(index) {
        const category = this.categories.objectAt(index);
        const result = confirm(this.intl.t('storefront.component.modals.manage-addons.delete-this-addon-category-assosiated-will-lost'));

        if (result) {
            this.categories = [...this.categories.filter((_, i) => i !== index)];
            yield category.destroyRecord();
        }
    }

    /**
     * Creates a new addon category with default settings.
     */
    @task *createAddonCategory() {
        const category = this.store.createRecord('addon-category', {
            name: this.intl.t('storefront.component.modals.manage-addons.untitled-addon-category'),
            for: 'storefront_product_addon',
            owner_type: 'storefront:store',
            owner_uuid: this.activeStore.id,
        });

        try {
            yield category.save();
            this.categories.pushObject(category);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Retrieves and sets the addon categories associated with the active store.
     */
    @task *getAddonCategories() {
        const categories = yield this.store.query('addon-category', { owner_uuid: this.activeStore.id });
        if (isArray(categories)) {
            this.categories = categories.map((category) => {
                category.addons = isArray(category.addons) ? category.addons.filter((addon) => !addon.isNew) : [];
                return category;
            });
        }
    }
}
