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
    @tracked buttonTitle = 'Select Category';

    context = {
        loadCategories: this.loadCategories,
        loadParentCategories: this.loadParentCategories,
        onSelectCategory: this.onSelectCategory,
        onCreateNewCategory: this.onCreateNewCategory,
    };

    constructor(owner, { network, category, onReady }) {
        super(...arguments);
        this.network = network;
        this.setCategory(category);

        if (typeof onReady === 'function') {
            onReady(this.context);
        }
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
        this.setCategory(category);

        if (typeof this.args.onSelect === 'function') {
            this.args.onSelect(category);
        }
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

    @action updateArgs(el, [category]) {
        this.setCategory(category);
    }

    setCategoryById(categoryId) {
        const category = this.store.peekRecord('category', categoryId);
        if (category) {
            this.setCategory(category);
        }
    }

    setCategory(category) {
        if (typeof category === 'string') {
            return this.setCategoryById(category);
        }

        this.selectedCategory = category;
        this.setButtonTitle(category);
        this.loadCategories(category);
    }
}
