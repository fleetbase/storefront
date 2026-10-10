import Component from '@glimmer/component';
import { get } from '@ember/object';
import { resolveResourceImage, getPlaceholderImage } from '@fleetbase/fleetops-data/utils/placeholder-images';

/**
 * A truck (the `food-truck` resource) on the shared card anatomy: the vehicle
 * pill as its identity, the vehicle photo as media, and what matters to a
 * store in the footer: whether it is online, where it serves, and which
 * catalogs it carries.
 */
export default class StorefrontTruckCardComponent extends Component {
    get truck() {
        return this.args.truck ?? this.args.foodTruck ?? this.args.resource;
    }

    get vehicle() {
        return get(this.truck, 'vehicle');
    }

    get isOnline() {
        const online = get(this.truck, 'online');

        if (typeof online === 'boolean') {
            return online;
        }

        return Boolean(this.vehicle && get(this.vehicle, 'online'));
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
        return get(this.truck, 'store.name');
    }

    /** What the store needs to know at a glance: serving, online with nothing to sell, or offline. */
    get presence() {
        if (!this.isActive) {
            return { status: 'inactive', labelKey: 'storefront.trucks.card.inactive' };
        }

        if (!this.isOnline) {
            return { status: 'offline', labelKey: 'storefront.trucks.card.offline' };
        }

        if (this.catalogs.length === 0) {
            return { status: 'warning', labelKey: 'storefront.trucks.card.no-catalog' };
        }

        return { status: 'online', labelKey: 'storefront.trucks.card.online' };
    }
}
