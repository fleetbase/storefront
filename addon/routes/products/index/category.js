import Route from '@ember/routing/route';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ProductsIndexCategoryRoute extends Route {
    @service store;
    @service currentUser;
    @service loader;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;
    @tracked categorySlug;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        public_id: { refreshModel: true },
        sku: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    @action willTransition({ targetName }) {
        if (typeof targetName === 'string' && !targetName.startsWith('console.storefront.products.index.category')) {
            this.controllerFor('products.index').category = null;
        }
    }

    @action loading(transition) {
        this.loader.showOnInitialTransition(transition, 'section.next-view-section', { loadingMessage: 'Loading products...' });
    }

    beforeModel() {
        if (this.abilities.cannot('storefront list product')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }

    model({ slug, ...params }) {
        this.categorySlug = slug;

        return this.store.query('product', {
            category_slug: slug,
            store_uuid: this.currentUser.getOption('activeStorefront'),
            ...params,
        });
    }

    setupController(controller) {
        const category = this.findCategoryFromSlug();
        controller.category = category;
        this.controllerFor('products.index').category = category;
    }

    findCategoryFromSlug() {
        return this.store.peekAll('category').find((category) => {
            return category.get('slug') === this.categorySlug && category.get('for') === 'storefront_product';
        });
    }
}
