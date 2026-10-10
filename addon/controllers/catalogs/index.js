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

    @action deleteCatalog(catalog) {
        this.crud.delete(catalog, {
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }
}
