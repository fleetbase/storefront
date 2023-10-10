import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';

export default class ProductsIndexIndexController extends Controller {
    /**
     * Inject the `filters` service
     *
     * @var {Service}
     */
    @service filters;

    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service currentUser;

    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service modalsManager;

    /**
     * Queryable parameters for this controller's model
     *
     * @var {Array}
     */
    queryParams = ['page', 'limit', 'sort', 'query', 'public_id', 'sku', 'created_at', 'updated_at'];

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

    @action viewProduct(product) {
        return this.transitionToRoute('products.index.category.edit', product);
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
