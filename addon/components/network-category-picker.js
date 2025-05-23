import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';
import isModel from '@fleetbase/ember-core/utils/is-model';

export default class NetworkCategoryPickerComponent extends Component {
    @service fetch;
    @service store;
    @service notifications;
    @tracked categories = [];
    @tracked selectedCategory;
    @tracked network;
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

    @task *loadCategories(parentCategory) {
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

        try {
            const categories = yield this.store.query('category', queryParams);
            this.categories = categories.toArray();
        } catch (error) {
            debug(`Unable to load categories : ${error.message}`)
        }
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

    async setCategoryById(categoryId) {
        const category = this.store.peekRecord('category', categoryId);
        if (category) {
            this.setCategory(category);
        } else {
            // load from server if possible
            const categoryRecord = await this.store.findRecord('category', categoryId);
            if (categoryRecord) {
                this.onSelectCategory(categoryRecord);
            }
        }
    }

    setCategory(category) {
        if (typeof category === 'string') {
            return this.setCategoryById(category);
        }

        this.selectedCategory = category;
        this.setButtonTitle(category);
        this.loadCategories.perform(category);
    }
}
