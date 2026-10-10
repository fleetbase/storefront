import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { task, timeout } from 'ember-concurrency';

export default class CatalogsIndexController extends Controller {
    @service store;
    @service intl;
    @service storefront;
    @service modalsManager;
    @service notifications;
    @service crud;
    @service hostRouter;
    queryParams = ['query'];

    @tracked query;

    /**
     * Debounced search; updates the `query` param the route refreshes on.
     */
    @task({ restartable: true }) *search({ target: { value } }) {
        if (isBlank(value)) {
            this.query = null;
            return;
        }

        yield timeout(250);
        this.query = value;
    }
    @tracked statusOptions = ['draft', 'published'];

    /**
     * The editor route renders in this template's outlet; the grid hides while it is open.
     */
    get isEditing() {
        return String(this.hostRouter.currentRouteName ?? '').endsWith('catalogs.index.edit');
    }

    @action createCatalog() {
        const catalog = this.store.createRecord('catalog', {
            store_uuid: this.storefront.activeStore.id,
            status: 'draft',
        });

        this.modalsManager.show('modals/create-catalog', {
            title: this.intl.t('storefront.catalogs.index.create-catalog'),
            acceptButtonText: this.intl.t('storefront.catalogs.editor.create-and-open'),
            acceptButtonIcon: 'arrow-right',
            statusOptions: this.statusOptions,
            catalog,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await catalog.save();
                    this.notifications.success(this.intl.t('storefront.catalogs.editor.created'));
                    modal.done();
                    return this.editCatalog(catalog);
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
            decline: (modal) => {
                catalog.destroyRecord();
                modal.done();
            },
        });
    }

    @action editCatalog(catalog) {
        return this.hostRouter.transitionTo('console.storefront.catalogs.index.edit', catalog.public_id ?? catalog.id);
    }

    /** Row actions behind the card's menu; the board lists duplicate, assign, publish/unpublish and delete. */
    get cardActions() {
        return [
            { id: 'duplicate', label: this.intl.t('storefront.catalogs.editor.duplicate'), icon: 'copy', fn: this.duplicateCatalog },
            { id: 'assign', label: this.intl.t('storefront.catalogs.card.assign'), icon: 'store', fn: this.assignCatalog },
            {
                id: 'publish',
                label: this.intl.t('storefront.catalogs.card.publish'),
                icon: 'upload',
                fn: this.publishCatalog,
                isVisible: (catalog) => catalog.status !== 'published',
            },
            {
                id: 'unpublish',
                label: this.intl.t('storefront.catalogs.card.unpublish'),
                icon: 'eye-slash',
                fn: this.unpublishCatalog,
                isVisible: (catalog) => catalog.status === 'published',
            },
            { separator: true },
            { id: 'delete', label: this.intl.t('storefront.common.delete'), icon: 'trash', class: 'text-red-500', fn: this.deleteCatalog },
        ];
    }

    /** A draft copy with the same categories and products, opened in the editor. */
    @action async duplicateCatalog(catalog) {
        const copy = this.store.createRecord('catalog', {
            store_uuid: catalog.store_uuid,
            name: `${catalog.name} (copy)`,
            description: catalog.description,
            status: 'draft',
        });

        try {
            const categories = (await catalog.categories)?.toArray?.() ?? [];
            categories.forEach((category) => {
                const clone = this.store.createRecord('catalog-category', { name: category.name, for: 'storefront_catalog', order: category.order });
                (category.products?.toArray?.() ?? []).forEach((product) => clone.products.pushObject(product));
                copy.categories.pushObject(clone);
            });

            await copy.save();
            this.notifications.success(this.intl.t('storefront.catalogs.editor.duplicated'));

            return this.editCatalog(copy);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action assignCatalog(catalog) {
        return this.hostRouter.transitionTo('console.storefront.catalogs.index.edit', catalog.public_id ?? catalog.id, { queryParams: { section: 'served-by' } });
    }

    @action publishCatalog(catalog) {
        return this.setCatalogStatus(catalog, 'published');
    }

    @action unpublishCatalog(catalog) {
        return this.setCatalogStatus(catalog, 'draft');
    }

    async setCatalogStatus(catalog, status) {
        const previous = catalog.status;
        catalog.set('status', status);

        try {
            await catalog.save();
            this.notifications.success(this.intl.t(status === 'published' ? 'storefront.catalogs.card.published' : 'storefront.catalogs.card.unpublished'));
        } catch (error) {
            catalog.set('status', previous);
            this.notifications.serverError(error);
        }
    }

    @action deleteCatalog(catalog) {
        this.crud.delete(catalog, {
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }
}
