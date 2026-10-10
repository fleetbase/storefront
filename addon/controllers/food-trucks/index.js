import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { task, timeout } from 'ember-concurrency';

export default class FoodTrucksIndexController extends Controller {
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
    @tracked statusOptions = ['active', 'inactive'];

    @action createFoodTruck() {
        const foodTruck = this.store.createRecord('food-truck', {
            store_uuid: this.storefront.activeStore.id,
            status: 'active',
        });

        this.modalsManager.show('modals/create-food-truck', {
            title: 'New Truck',
            statusOptions: this.statusOptions,
            foodTruck,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await foodTruck.save();
                    this.hostRouter.refresh();
                    this.notifications.success('New truck created.');
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
        });
    }

    @action editFoodTruck(foodTruck) {
        this.modalsManager.show('modals/create-food-truck', {
            title: 'Edit Truck',
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            statusOptions: this.statusOptions,
            foodTruck,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await foodTruck.save();
                    this.hostRouter.refresh();
                    this.notifications.success('Changes to truck saved.');
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
        });
    }

    @action async assignCatalogs(foodTruck) {
        const allCatalogs = await this.store.query('catalog', { limit: -1 });
        console.log('[allCatalogs]', allCatalogs);
        this.modalsManager.show('modals/assign-food-truck-catalogs', {
            title: 'Assign catalogs to this truck',
            acceptButtonText: 'Done',
            acceptButtonIcon: 'save',
            foodTruck,
            allCatalogs,
            updateCatalogSelections: (catalogs) => {
                foodTruck.set('catalogs', catalogs);
            },
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await foodTruck.save();
                    this.hostRouter.refresh();
                    this.notifications.success('Changes to truck saved.');
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
        });
    }

    @action deleteFoodTruck(foodTruck) {
        this.crud.delete(foodTruck, {
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }
}
