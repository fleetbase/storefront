import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { truckLatLng } from '@fleetbase/storefront-engine/components/storefront/truck/map';
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

    /** A truck takes orders while its status is active; whether its vehicle reports online has no say. */
    isActive(truck) {
        return get(truck, 'status') === 'active';
    }

    get statusTabs() {
        const trucks = this.trucks;
        const active = trucks.filter((truck) => this.isActive(truck));

        return [
            { id: 'all', label: this.intl.t('storefront.common.all'), count: trucks.length },
            { id: 'active', label: this.intl.t('storefront.trucks.index.active'), count: active.length },
            { id: 'inactive', label: this.intl.t('storefront.trucks.index.inactive'), count: trucks.length - active.length },
        ].map((tab) => ({ ...tab, isActive: tab.id === this.statusTab }));
    }

    get visibleTrucks() {
        const trucks = this.trucks;

        switch (this.statusTab) {
            case 'active':
                return trucks.filter((truck) => this.isActive(truck));
            case 'inactive':
                return trucks.filter((truck) => !this.isActive(truck));
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

    @tracked view = 'cards';

    get isMapView() {
        return this.view === 'map';
    }

    /** Trucks whose vehicle reported a position, for the map view. */
    get mappableTrucks() {
        return this.visibleTrucks.filter((truck) => truckLatLng(truck));
    }

    @action toggleMapView() {
        this.view = this.isMapView ? 'cards' : 'map';
    }

    @action openVehicle(truck) {
        const vehicleId = get(truck, 'vehicle.public_id');

        return vehicleId ? this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index.details', vehicleId) : null;
    }

    /** An order from the truck panel opens on this context's orders page. */
    @action viewOrder(order) {
        return this.hostRouter.transitionTo('console.storefront.orders.index', { queryParams: { query: order.public_id } });
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
