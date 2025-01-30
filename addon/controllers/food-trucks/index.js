import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class FoodTrucksIndexController extends Controller {
    @service store;
    @service intl;
    @service storefront;
    @service modalsManager;
    @service notifications;
    @service crud;
    @service hostRouter;
    @tracked statusOptions = ['active', 'inactive'];

    @action createFoodTruck() {
        console.log('create food truck fn called!');
        const foodTruck = this.store.createRecord('food-truck', {
            store_uuid: this.storefront.activeStore.id,
            status: 'active',
        });

        this.modalsManager.show('modals/create-food-truck', {
            title: 'New Food Truck',
            statusOptions: this.statusOptions,
            foodTruck,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await foodTruck.save();
                    this.hostRouter.refresh();
                    this.notifications.success('New food truck saved');
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
