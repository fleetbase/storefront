import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import isModel from '@fleetbase/ember-core/utils/is-model';

export default class NetworkCategoryPickerComponent extends Component {
    @service fetch;
    @service store;
    @service notifications;
    @tracked categories = [];
    @tracked selectedCategory;
    @tracked network;
    @tracked isLoading = false;
    @tracked buttonTitle = null;

    constructor() {
        super(...arguments);
        this.network = this.args.network;
        this.category = this.args.category;
        this.setButtonTitle(this.category);
        this.loadCategories(this.category);
    }

    setButtonTitle(selectedCategory) {
        let buttonTitle = this.args.buttonTitle ?? 'Select Category';

        if (selectedCategory) {
            buttonTitle = selectedCategory.name;
        }

        this.buttonTitle = buttonTitle;
    }

    @action loadCategories(parentCategory) {
        const queryParams = {
            owner_uuid: this.network.id,
            parents_only: parentCategory ? false : true,
            with_subcategories: true,
            for: 'storefront_network',
        };

        if (isModel(parentCategory)) {
            queryParams.parent = parentCategory.id;
            queryParams.with_parent = true;
        }

        this.isLoading = true;
        this.store
            .query('category', queryParams)
            .then((categories) => {
                this.categories = categories.toArray();
            })
            .finally(() => {
                this.isLoading = false;
            });
    }

    @action onSelectCategory(category) {
        this.selectedCategory = category;
        this.setButtonTitle(category);

        if (typeof this.args.onSelect === 'function') {
            this.args.onSelect(category);
        }

        this.loadCategories(category);
    }

    @action onCreateNewCategory() {
        if (typeof this.args.onCreateNewCategory === 'function') {
            this.args.onCreateNewCategory(this, this.selectedCategory);
        }
    }

    @action async loadParentCategories() {
        if (this.selectedCategory.parent) {
            return this.onSelectCategory(this.selectedCategory.parent);
        }

        this.onSelectCategory(null);
    }
}
