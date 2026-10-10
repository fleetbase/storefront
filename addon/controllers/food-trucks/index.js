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
    @tracked statusTab = 'all';
    @tracked activeTruck = null;
    @tracked panelTab = 'details';
    @tracked catalogs = [];

    get trucks() {
        return this.model?.toArray?.() ?? Array.from(this.model ?? []);
    }

    get statusTabs() {
        const trucks = this.trucks;
        const online = trucks.filter((truck) => truck.status !== 'inactive' && (truck.online || truck.vehicle?.online));
        const inactive = trucks.filter((truck) => truck.status === 'inactive');
        const offline = trucks.filter((truck) => !online.includes(truck) && !inactive.includes(truck));

        return [
            { id: 'all', label: this.intl.t('storefront.common.all'), count: trucks.length },
            { id: 'online', label: this.intl.t('storefront.common.online'), count: online.length },
            { id: 'offline', label: this.intl.t('storefront.common.offline'), count: offline.length },
            { id: 'inactive', label: this.intl.t('storefront.trucks.index.inactive'), count: inactive.length },
        ].map((tab) => ({ ...tab, isActive: tab.id === this.statusTab }));
    }

    get visibleTrucks() {
        const trucks = this.trucks;

        switch (this.statusTab) {
            case 'online':
                return trucks.filter((truck) => truck.status !== 'inactive' && (truck.online || truck.vehicle?.online));
            case 'offline':
                return trucks.filter((truck) => truck.status !== 'inactive' && !(truck.online || truck.vehicle?.online));
            case 'inactive':
                return trucks.filter((truck) => truck.status === 'inactive');
            default:
                return trucks;
        }
    }

    /**
     * Attributes a new truck starts with; the network trucks page overrides the owner.
     */
    get newTruckAttributes() {
        return { store_uuid: this.storefront.activeStore?.id, status: 'active' };
    }

    @action selectStatusTab(tab) {
        this.statusTab = tab.id ?? tab;
    }

    @action openTruck(truck, tab = 'details') {
        this.panelTab = typeof tab === 'string' ? tab : 'details';
        this.activeTruck = truck;
    }

    @action closeTruckPanel() {
        this.activeTruck = null;
    }

    @action onTruckSaved() {
        return this.hostRouter.refresh();
    }

    @action createFoodTruck() {
        const foodTruck = this.store.createRecord('food-truck', this.newTruckAttributes);

        return this.openTruck(foodTruck, 'details');
    }

    @action editFoodTruck(foodTruck) {
        return this.openTruck(foodTruck, 'details');
    }

    @action assignCatalogs(foodTruck) {
        return this.openTruck(foodTruck, 'catalogs');
    }

    @action deleteFoodTruck(foodTruck) {
        this.crud.delete(foodTruck, {
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }
}
