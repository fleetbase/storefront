import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * Bulk "Add to catalog": pick one of the store's catalogs, then one of its categories.
 * The chosen records are kept on the modal options for the controller's confirm.
 */
export default class ModalsAddProductsToCatalogComponent extends Component {
    @service modalsManager;
    @service store;

    get categories() {
        const catalog = this.args.options?.catalog;

        return catalog?.categories?.toArray?.() ?? Array.from(catalog?.categories ?? []);
    }

    @action async selectCatalog(catalog) {
        // The listing payload is shallow; the editor's payload carries categories and products.
        const loaded = catalog?.id ? await this.store.findRecord('catalog', catalog.id, { reload: true }).catch(() => catalog) : null;

        this.modalsManager.setOption('catalog', loaded);
        this.modalsManager.setOption('category', null);
    }

    @action selectCategory(categoryId) {
        const category = this.categories.find((candidate) => candidate.id === categoryId) ?? null;

        this.modalsManager.setOption('category', category);
    }
}
