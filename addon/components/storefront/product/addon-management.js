import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';
import { all } from 'rsvp';

export default class StorefrontProductAddonManagementComponent extends Component {
    @service store;
    @service intl;
    @service currentUser;
    @service modalsManager;
    @service notifications;

    @tracked categories = [];

    constructor() {
        super(...arguments);
        this.args.onReady?.(this);
        this.getAddonCategories.perform();
    }

    get activeStore() {
        return this.args.store;
    }

    @action createNewAddon(category) {
        const productAddon = this.store.createRecord('product-addon', { category_uuid: category.id });
        category.addons.pushObject(productAddon);
    }

    @task *saveChanges() {
        this.modalsManager.startLoading();

        try {
            yield all(this.categories.map((category) => category.save()));
        } catch (error) {
            this.modalsManager.stopLoading();
            return this.notifications.serverError(error);
        }

        yield this.modalsManager.done();
        this.categories = [];
    }

    @task *removeAddon(category, index) {
        const addon = category.addons.objectAt(index);
        category.addons.removeAt(index);

        if (addon.id) {
            yield addon.destroyRecord();
        }
    }

    @task *saveAddonCategory(category) {
        yield category.save();
    }

    @task *deleteAddonCategory(index) {
        const category = this.categories.objectAt(index);
        const result = confirm(this.intl.t('storefront.component.modals.manage-addons.delete-this-addon-category-assosiated-will-lost'));

        if (result) {
            this.categories = this.categories.filter((_, i) => i !== index);
            yield category.destroyRecord();
        }
    }

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
