import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class CatalogsIndexController extends Controller {
    @service store;
    @service intl;
    @service storefront;
    @service modalsManager;
    @service notifications;
    @service crud;
    @service hostRouter;
    @tracked statusOptions = ['draft', 'published'];

    @action createCatalog() {
        const catalog = this.store.createRecord('catalog', {
            store_uuid: this.storefront.activeStore.id,
            status: 'draft',
        });

        return this.editCatalog(catalog, {
            title: 'New Catalog',
            acceptButtonText: 'Confirm',
            acceptButtonIcon: 'check',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await catalog.save();
                    this.hostRouter.refresh();
                    this.notifications.success('New catalog created.');
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
        });
    }

    @action editCatalog(catalog, modalOptions = {}) {
        const allProducts = this.store.query('product', { limit: -1 });

        this.modalsManager.show('modals/create-catalog', {
            title: 'Edit Food Truck',
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            statusOptions: this.statusOptions,
            catalog,
            allProducts,
            selectedProducts: [],
            toggleCategory: (category) => {
                category.set('expanded', !category.get('expanded'));
            },
            finishAddingProducts: (category) => {
                category.setProperties({
                    adding_products: false,
                });
            },
            confirmSelectedProducts: (category, products) => {
                category.setProperties({
                    products,
                });
            },
            removeProduct: (category, product) => {
                const products = category.get('products').filter((p) => p.id !== product.id);
                category.set('products', products);
            },
            addProducts: (category) => {
                category.setProperties({
                    expanded: true,
                    adding_products: true,
                });
            },
            createCategory: () => {
                const name = prompt('Enter category name');
                const category = this.store.createRecord('catalog-category', { name });
                catalog.categories.pushObject(category);
            },
            editCategory: (category) => {
                const name = prompt('Change category name', category.name);
                category.set('name', name);
            },
            deleteCategory: async (category) => {
                const confirmed = confirm('Delete this category?');
                if (confirmed) {
                    category.set('deleting', true);

                    try {
                        await category.destroyRecord();
                    } catch (error) {
                        this.notifications.serverError(error);
                    }
                }
            },
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await catalog.save();
                    this.hostRouter.refresh();
                    this.notifications.success('Changes to catalog saved.');
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
            ...modalOptions,
        });
    }

    @action deleteCatalog(catalog) {
        this.crud.delete(catalog, {
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }
}
