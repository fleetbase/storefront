import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';

export default class NetworksIndexNetworkCategoriesController extends BaseController {
    @service intl;
    @service networkCategories;
    @tracked network;
    @tracked stores = [];
    @tracked table;

    get categories() {
        return this.model?.toArray?.() ?? Array.from(this.model ?? []);
    }

    /** Store counts per category, from the member stores' category assignment. */
    get storeCountsByCategory() {
        return this.stores.reduce((counts, store) => {
            const id = get(store, 'category.id') ?? get(store, 'category_uuid');

            if (id) {
                counts.set(id, (counts.get(id) ?? 0) + 1);
            }

            return counts;
        }, new Map());
    }

    get rows() {
        const counts = this.storeCountsByCategory;

        return this.categories.map((category) => {
            category.set('stores_count', counts.get(category.id) ?? 0);

            return category;
        });
    }

    get columns() {
        return [
            {
                id: 'name',
                label: this.intl.t('storefront.common.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/category-identity',
                width: '220px',
                resizable: true,
                sortable: true,
            },
            {
                id: 'description',
                label: this.intl.t('storefront.common.description'),
                valuePath: 'description',
                cellComponent: 'table/cell/base',
                resizable: true,
            },
            {
                id: 'parent',
                label: this.intl.t('storefront.networks.categories.parent'),
                valuePath: 'parent.name',
                cellComponent: 'table/cell/base',
                width: '160px',
                resizable: true,
            },
            {
                id: 'stores-count',
                label: this.intl.t('storefront.networks.index.network.stores.store'),
                valuePath: 'stores_count',
                cellComponent: 'table/cell/base',
                width: '100px',
            },
            {
                id: 'row-actions',
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('storefront.common.actions'),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                width: '50px',
                actions: [
                    { id: 'edit', label: this.intl.t('storefront.common.edit'), fn: this.editCategory },
                    { id: 'add-child', label: this.intl.t('storefront.networks.categories.add-subcategory'), fn: this.createSubcategory },
                    { separator: true },
                    { id: 'delete', label: this.intl.t('storefront.common.delete'), fn: this.deleteCategory, class: 'text-red-500' },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
            },
        ];
    }

    @action refresh() {
        return this.hostRouter.refresh();
    }

    @action createCategory() {
        return this.networkCategories.create(this.network, null, { onCreated: this.refresh });
    }

    @action createSubcategory(category) {
        return this.networkCategories.create(this.network, category, { onCreated: this.refresh });
    }

    @action editCategory(category) {
        return this.networkCategories.edit(this.network, category, { onSaved: this.refresh });
    }

    @action deleteCategory(category) {
        return this.networkCategories.remove(this.network, category, { onDeleted: this.refresh });
    }
}
