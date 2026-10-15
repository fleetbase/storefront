import Component from '@glimmer/component';
import { get } from '@ember/object';
import { resolveResourceImage, getPlaceholderImage } from '@fleetbase/fleetops-data/utils/placeholder-images';
import { catalogServingNow } from './panel';

/**
 * A truck (the `food-truck` resource) on the shared card anatomy: the vehicle
 * pill as its identity, the vehicle photo as media, and what matters to a
 * store in the footer: whether it is active (taking orders), where it serves,
 * and which catalogs it carries. Whether its vehicle reports online has no say.
 */
export default class StorefrontTruckCardComponent extends Component {
    /** Opens the truck from its title, when the page gives a way to. */
    get openTruck() {
        return typeof this.args.onOpen === 'function' ? () => this.args.onOpen(this.truck) : null;
    }

    get truck() {
        return this.args.truck ?? this.args.foodTruck ?? this.args.resource;
    }

    get vehicle() {
        return get(this.truck, 'vehicle');
    }

    get status() {
        return get(this.truck, 'status') ?? 'inactive';
    }

    get isActive() {
        return this.status === 'active';
    }

    get photoUrl() {
        return resolveResourceImage(this.vehicle ? get(this.vehicle, 'photo_url') : null, 'vehicle');
    }

    get placeholder() {
        return getPlaceholderImage('vehicle');
    }

    /** The driver Fleet-Ops assigned to the vehicle, when it has one. */
    get driverName() {
        const vehicle = this.vehicle;

        if (!vehicle) {
            return null;
        }

        return get(vehicle, 'driver_name') ?? get(vehicle, 'driver.name') ?? null;
    }

    /** Orders fulfilled from this truck today, as the API counts them. */
    get ordersToday() {
        return Number(get(this.truck, 'today.orders') ?? 0);
    }

    get serviceAreaName() {
        return get(this.truck, 'service_area.name');
    }

    get zoneName() {
        return get(this.truck, 'zone.name');
    }

    get catalogs() {
        const catalogs = get(this.truck, 'catalogs');

        return catalogs ? catalogs.slice() : [];
    }

    get storeName() {
        return get(this.truck, 'store_summary.name');
    }

    /** The assigned catalogs within their hours right now. */
    get servingCatalogs() {
        return this.catalogs.filter((catalog) => get(catalog, 'status') !== 'draft' && catalogServingNow(catalog));
    }

    /** What the store needs to know at a glance: serving which catalog, active with nothing to sell, or inactive. */
    get presence() {
        if (!this.isActive) {
            return { status: 'inactive', labelKey: 'storefront.trucks.card.inactive' };
        }

        if (this.catalogs.length === 0) {
            return { status: 'warning', labelKey: 'storefront.trucks.card.no-catalog' };
        }

        const serving = this.servingCatalogs;

        if (serving.length === 0) {
            return { status: 'warning', labelKey: 'storefront.trucks.card.no-catalog-in-hours' };
        }

        return { status: 'online', labelKey: 'storefront.trucks.card.serving', catalog: get(serving[0], 'name') };
    }
}
