import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { task } from 'ember-concurrency';

const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

function toMinutes(value) {
    const [hours, minutes] = String(value ?? '')
        .split(':')
        .map((part) => parseInt(part, 10));

    return Number.isFinite(hours) ? hours * 60 + (Number.isFinite(minutes) ? minutes : 0) : null;
}

/**
 * Whether a catalog is being served right now by its hours; a catalog without hours is always on.
 */
export function catalogServingNow(catalog, now = new Date()) {
    const hours = catalog?.hours?.toArray?.() ?? Array.from(catalog?.hours ?? []);

    if (!hours.length) {
        return true;
    }

    const today = DAYS[now.getDay()];
    const minutes = now.getHours() * 60 + now.getMinutes();

    return hours.some((hour) => {
        const day = String(hour.day_of_week ?? '').toLowerCase();

        if (!day.startsWith(today.slice(0, 3))) {
            return false;
        }

        const start = toMinutes(hour.start);
        const end = toMinutes(hour.end);

        return (start === null || minutes >= start) && (end === null || minutes <= end);
    });
}

/**
 * The truck panel: vehicle and crew, where it serves, its status, and the catalogs it sells
 * from. Replaces the create/edit and assign-catalogs modals.
 */
export default class StorefrontTruckPanelComponent extends Component {
    @service intl;
    @service notifications;
    @tracked tab = this.args.tab ?? 'details';
    @tracked revision = 0;

    statusOptions = ['active', 'inactive'];

    get truck() {
        return this.args.truck;
    }

    get catalogs() {
        return this.args.catalogs ?? [];
    }

    get assignedIds() {
        this.revision;

        return new Set((this.truck?.catalogs?.toArray?.() ?? Array.from(this.truck?.catalogs ?? [])).map((catalog) => catalog.id));
    }

    get catalogRows() {
        const assigned = this.assignedIds;
        const now = new Date();

        return this.catalogs.map((catalog) => {
            const isAssigned = assigned.has(catalog.id);
            const servingNow = catalogServingNow(catalog, now);
            const hours = catalog.hours?.toArray?.() ?? Array.from(catalog.hours ?? []);

            return {
                catalog,
                isAssigned,
                servingNow: isAssigned && servingNow && catalog.status === 'published',
                hoursSummary: hours.length ? `${hours[0].start ?? ''}${hours[0].end ? ` – ${hours[0].end}` : ''}` : this.intl.t('storefront.catalogs.editor.no-hours'),
                productsCount: catalog.productsCount ?? 0,
                ownerName: catalog.store?.name ?? null,
            };
        });
    }

    get assignedCount() {
        return this.assignedIds.size;
    }

    get servingNowCount() {
        return this.catalogRows.filter((row) => row.servingNow).length;
    }

    get tabs() {
        return [
            { id: 'details', label: this.intl.t('storefront.trucks.panel.tabs.details') },
            { id: 'catalogs', label: this.intl.t('storefront.trucks.panel.tabs.catalogs'), count: this.assignedCount },
        ].map((tab) => ({ ...tab, isActive: tab.id === this.tab }));
    }

    get isDirty() {
        this.revision;

        return Boolean(this.truck?.isNew || this.truck?.hasDirtyAttributes || this.catalogsChanged);
    }

    get catalogsChanged() {
        const current = [...this.assignedIds].sort().join(',');

        return current !== (this.initialCatalogIds ?? '');
    }

    initialCatalogIds = null;

    constructor() {
        super(...arguments);
        this.initialCatalogIds = [...this.assignedIds].sort().join(',');
    }

    get title() {
        if (this.truck?.isNew) {
            return this.intl.t('storefront.trucks.panel.new-truck');
        }

        // `vehicle` is a belongsTo proxy until it resolves; read through `get`.
        return get(this.truck, 'vehicle.display_name') ?? get(this.truck, 'vehicle.plate_number') ?? this.truck?.public_id ?? this.intl.t('storefront.common.food-trucks');
    }

    @action selectTab(tab) {
        this.tab = tab.id ?? tab;
    }

    @action setVehicle(vehicle) {
        this.truck.set('vehicle', vehicle);
        this.truck.set('vehicle_uuid', vehicle?.id ?? null);
        this.revision++;
    }

    @action setServiceArea(serviceArea) {
        this.truck.set('service_area', serviceArea);
        this.truck.set('zone', null);
        this.revision++;
    }

    @action setZone(zone) {
        this.truck.set('zone', zone);
        this.revision++;
    }

    @action setStatus(status) {
        this.truck.set('status', status);
        this.revision++;
    }

    @action toggleCatalog(catalog) {
        const catalogs = this.truck.catalogs;

        if (this.assignedIds.has(catalog.id)) {
            catalogs.removeObject(catalog);
        } else {
            catalogs.pushObject(catalog);
        }

        this.revision++;
    }

    @task *save() {
        try {
            yield this.truck.save();
            this.initialCatalogIds = [...this.assignedIds].sort().join(',');
            this.revision++;
            this.notifications.success(this.intl.t('storefront.trucks.panel.saved'));
            this.args.onSaved?.(this.truck);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action discard() {
        if (this.truck?.isNew) {
            this.args.onClose?.();
            return;
        }

        this.truck.rollbackAttributes();
        this.revision++;
    }

    @action close() {
        if (this.truck?.isNew && !this.truck.isSaving) {
            this.truck.rollbackAttributes();
        }

        this.args.onClose?.();
    }
}
