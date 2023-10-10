import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { dasherize } from '@ember/string';
import { isBlank } from '@ember/utils';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';

export default class ProductsIndexCategoryController extends Controller {
    @service modalsManager;
    @service fetch;
    @service hostRouter;
    @tracked category;

    /**
     * The current page of data being viewed
     *
     * @var {Integer}
     */
    @tracked page = 1;

    /**
     * The maximum number of items to show per page
     *
     * @var {Integer}
     */
    @tracked limit;

    /**
     * The param to query with.
     *
     * @var {String}
     */
    @tracked query;

    /**
     * The param to sort the data on, the param with prepended `-` is descending
     *
     * @var {String}
     */
    @tracked sort;

    /**
     * The filterable param `public_id`
     *
     * @var {String}
     */
    @tracked public_id;

    /**
     * The filterable param `drivers_license_number`
     *
     * @var {String}
     */
    @tracked sku;

    /**
     * The filterable param `created_at`
     *
     * @var {String}
     */
    @tracked created_at;

    /**
     * The filterable param `updated_at`
     *
     * @var {String}
     */
    @tracked updated_at;

    /**
     * The search task.
     *
     * @void
     */
    @task({ restartable: true }) *search({ target: { value } }) {
        // if no query don't search
        if (isBlank(value)) {
            this.query = null;
            return;
        }

        // timeout for typing
        yield timeout(250);

        // reset page for results
        if (this.page > 1) {
            this.page = 1;
        }

        // update the query param
        this.query = value;
    }

    @action deleteCategory() {
        if (!this.category) {
            return;
        }

        this.modalsManager.confirm({
            title: 'Are you sure you wish to delete this category?',
            body: 'Warning! Deleting this category will also remove all products listed under this category.',
            confirm: (modal) => {
                modal.startLoading();

                return this.category.destroyRecord().then(() => {
                    return this.transitionToRoute('products.index');
                });
            },
        });
    }

    @action editCategory(category) {
        this.modalsManager.show('modals/create-product-category', {
            title: `Edit category: ${category.name}`,
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            category,
            uploadNewPhoto: (file) => {
                this.fetch.uploadFile.perform(
                    file,
                    {
                        path: `uploads/${category.company_uuid}/product-category-icon/${dasherize(category.name ?? this.currentUser.companyId)}`,
                        subject_uuid: category.id,
                        subject_type: `category`,
                        type: `category_icon`,
                    },
                    (uploadedFile) => {
                        category.setProperties({
                            icon_file_uuid: uploadedFile.id,
                            icon_url: uploadedFile.url,
                            icon: uploadedFile,
                        });
                    }
                );
            },
            confirm: (modal) => {
                modal.startLoading();

                return category.save().then(() => {
                    this.notifications.success('Category changes saved.');
                });
            },
        });
    }

    @action deleteProduct(product) {
        this.modalsManager.confirm({
            title: 'Are you sure you wish to delete this product?',
            body: 'Warning! Once this product is deleted it will no longer be accessible.',
            confirm: (modal) => {
                modal.startLoading();

                return product.destroyRecord().then(() => {
                    return this.hostRouter.refresh();
                });
            },
        });
    }
}
