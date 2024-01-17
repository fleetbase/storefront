import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ModalsManageAddonsComponent extends Component {
    @service store;
    @service intl;
    @service currentUser;
    @service modalsManager;
    @tracked categories = [];
    @tracked isLoading = true;

    @action saveAddon(addon) {
        this.isLoading = true;

        return addon.save().then(() => {
            this.isLoading = false;
        });
    }

    @action insertNewAddon(category) {
        const productAddon = this.store.createRecord('product-addon', { category_uuid: category.id });
        category.addons.pushObject(productAddon);
    }

    @action removeAddon(category, index) {
        const addon = category.addons.objectAt(index);

        category.addons.removeAt(index);
        return addon.destroyRecord();
    }

    @action saveCategory(category) {
        this.isLoading = true;
        return category.save().then(() => {
            this.isLoading = false;
        });
    }

    @action deleteCategory(index) {
        const category = this.categories.objectAt(index);
        const result = confirm(this.intl.t('storefront.component.modals.manage-addons.delete-this-addon-category-assosiated-will-lost'));

        if (result) {
            this.categories.removeAt(index);
            return category.destroyRecord();
        }
    }

    @action pushCategory(category) {
        const { categories } = this;
        categories.pushObject(category);
    }

    @action createCategory(store) {
        const category = this.store.createRecord('addon-category', {
            name: this.intl.t('storefront.component.modals.manage-addons-untitled-addon-category'),
            for: 'storefront_product_addon',
            owner_type: 'storefront:store',
            owner_uuid: store.id,
        });

        this.isLoading = true;

        return category.save().then((category) => {
            this.pushCategory(category);
            this.isLoading = false;
        });
    }

    @action fetchCategories(store) {
        this.isLoading = true;

        return this.store
            .query('addon-category', {
                owner_uuid: store.id,
            })
            .then((categories) => {
                this.categories = categories.toArray();
            })
            .finally(() => {
                this.isLoading = false;
            });
    }
}
