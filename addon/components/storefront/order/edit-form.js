import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import { format, isValid, parseISO } from 'date-fns';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';

const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

// What a drop-off is made of. A saved place picked as it is goes by its id; anything typed, moved
// or added to goes as these fields and becomes a new place on save.
const ADDRESS_FIELDS = ['name', 'street1', 'street2', 'neighborhood', 'district', 'building', 'city', 'province', 'postal_code', 'country'];
const COMPARED_FIELDS = ['street1', 'street2', 'city', 'postal_code', 'phone', 'instructions'];

function cents(value) {
    const parsed = parseFloat(value);

    return Number.isFinite(parsed) ? Math.round(parsed) : 0;
}

function toArray(value) {
    return value?.toArray?.() ?? Array.from(value ?? []);
}

function firstName(name) {
    return String(name ?? '').split(' ')[0];
}

/** A place's [lat, lng], or null when it has no usable point. */
function coordinates(place) {
    const lat = Number(place?.latitude ?? place?.location?.coordinates?.[1]);
    const lng = Number(place?.longitude ?? place?.location?.coordinates?.[0]);

    return Number.isFinite(lat) && Number.isFinite(lng) && (lat || lng) ? [lat, lng] : null;
}

function samePoint(a, b) {
    if (!a || !b) {
        return a === b;
    }

    return Math.abs(a[0] - b[0]) < 0.00001 && Math.abs(a[1] - b[1]) < 0.00001;
}

function addressOf(place) {
    const address = {};
    ADDRESS_FIELDS.forEach((field) => (address[field] = place?.[field] ?? ''));
    address.phone = place?.phone ?? '';
    address.instructions = place?.meta?.instructions ?? place?.meta?.delivery_instructions ?? '';
    address.location = coordinates(place);

    return address;
}

function kilometres(meters) {
    return meters >= 1000 ? `${(meters / 1000).toFixed(1)} km` : `${Math.round(meters)} m`;
}

/**
 * The 11b edit form: the order panel swaps to it in place. Every field shows what it was; the
 * drop-off is an address search with a pin to drag to the exact entrance; items change quantity,
 * options or get a substitute; the footer counts the changes, prices them (the same math as
 * checkout) and says what happens to the money and who is told before anything is saved. What the
 * order's stage locks stays visible and says why.
 */
export default class StorefrontOrderEditFormComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service store;
    @service storefront;
    @service hostRouter;
    @service storefrontOrderActions;

    @tracked overview = null;
    @tracked preview = null;
    @tracked previewError = null;
    @tracked products = [];
    @tracked places = [];
    @tracked customer = null;
    @tracked address = {};
    @tracked addressQuery = '';
    @tracked selectedPlace = null;
    @tracked savePlace = true;
    @tracked newDistance = null;
    @tracked contact = {};
    @tracked contactScope = 'profile';
    @tracked lines = [];
    @tracked isScheduled = false;
    @tracked scheduledAt = null;
    @tracked notes = '';
    @tracked settlement = null;
    @tracked notifyCustomer = true;
    @tracked notifyDriver = true;
    @tracked picker = null;

    map = null;
    pin = null;
    wasPin = null;

    constructor() {
        super(...arguments);
        this.reset();
        this.load.perform();
    }

    get order() {
        return this.args.order;
    }

    get currency() {
        return this.order?.meta?.currency ?? this.preview?.proposed?.currency ?? 'USD';
    }

    get isPickup() {
        return Boolean(this.order?.meta?.is_pickup);
    }

    get customerName() {
        return this.order?.customer?.name ?? this.originalContact?.name ?? '';
    }

    get customerFirstName() {
        return firstName(this.customerName) || this.intl.t('storefront.order.edit.the-customer');
    }

    reset() {
        const order = this.order;
        const customer = order?.customer;

        this.lines = toArray(order?.payload?.entities).map((entity) => {
            const quantity = Number(entity.meta?.quantity) || 1;
            const subtotal = cents(entity.meta?.subtotal);

            return {
                key: entity.public_id,
                entity: entity.public_id,
                productId: entity.meta?.product_id ?? null,
                name: entity.name,
                image: entity.photo_url ?? entity.meta?.image_url,
                quantity,
                originalQuantity: quantity,
                variants: entity.meta?.variants ?? [],
                addons: entity.meta?.addons ?? [],
                unitPrice: Math.round(subtotal / quantity),
                unavailable: Boolean(entity.meta?.unavailable),
                optionsChanged: false,
                removed: false,
                isNew: false,
            };
        });
        this.contact = { name: customer?.name ?? '', phone: customer?.phone ?? '', email: customer?.email ?? '' };
        this.originalContact = { ...this.contact };
        this.originalAddress = addressOf(this.currentDropoff);
        this.address = { ...this.originalAddress };
        this.addressQuery = this.currentDropoff?.address ?? '';
        this.selectedPlace = null;
        this.newDistance = null;
        this.isScheduled = Boolean(order?.scheduled_at);
        this.scheduledAt = order?.scheduled_at ?? null;
        this.notes = order?.notes ?? '';
        this.settlement = null;
        this.preview = null;
        this.picker = null;
    }

    @task *load() {
        const order = this.order;
        try {
            this.overview = yield this.fetch.get(`orders/${order.public_id}/overview`, {}, { namespace: 'storefront/int/v1' });
            // The header shows the stage note from the same overview.
            this.storefrontOrderActions.panel.setOverview(order.public_id, this.overview);
        } catch (error) {
            this.notifications.serverError(error);
        }

        const [store, contact] = yield Promise.all([
            order.meta?.storefront_id ? this.store.findRecord('store', order.meta.storefront_id).catch(() => null) : null,
            order.customer?.public_id ? this.store.findRecord('contact', order.customer.public_id).catch(() => null) : null,
        ]);

        this.customer = contact;
        if (contact) {
            this.places = toArray(yield contact.places);
        }
        if (store) {
            this.products = toArray(yield this.store.query('product', { store_uuid: store.id, limit: -1, status: 'published' }).catch(() => []));
        }
    }

    /* What the order's stage allows */

    lockFor(field) {
        return this.overview?.edit?.locked?.[field] ?? null;
    }

    get itemsLock() {
        return this.lockFor('items');
    }

    get scheduleLock() {
        return this.lockFor('schedule');
    }

    get closedReason() {
        return this.lockFor('dropoff')?.reason ?? this.lockFor('notes')?.reason ?? null;
    }

    get isClosed() {
        return this.overview?.edit?.stage === 'closed';
    }

    get itemsLocked() {
        return this.lockFor('items')?.rule === 'locked' || this.isClosed;
    }

    get itemsRemovalOnly() {
        return this.lockFor('items')?.rule === 'removal_only';
    }

    /** The drop-off's lock, when the stage or a finished order locks it. */
    get dropoffLocked() {
        if (this.isClosed) {
            return { reason: this.closedReason };
        }

        return this.lockFor('dropoff');
    }

    get scheduleLocked() {
        return this.lockFor('schedule')?.rule === 'locked' || this.isClosed;
    }

    /* Drop-off: an address search, the unit and door contact, instructions and a pin */

    get currentDropoff() {
        return this.order?.payload?.dropoff;
    }

    get savedPlaces() {
        const current = this.currentDropoff?.id;

        return this.places.filter((place) => place.id !== current).map((place) => ({ place, isSelected: this.selectedPlace?.id === place.id }));
    }

    get dropoffChanged() {
        const was = this.originalAddress ?? {};
        const now = this.address ?? {};

        return COMPARED_FIELDS.some((field) => (now[field] ?? '') !== (was[field] ?? '')) || !samePoint(now.location, was.location);
    }

    /** A saved place picked and left as it is goes by its id. */
    get selectedPlaceUntouched() {
        if (!this.selectedPlace) {
            return false;
        }
        const place = addressOf(this.selectedPlace);

        return COMPARED_FIELDS.every((field) => (this.address[field] ?? '') === (place[field] ?? '')) && samePoint(this.address.location, place.location);
    }

    get canSavePlace() {
        return Boolean(this.customer) && this.dropoffChanged && !this.selectedPlaceUntouched;
    }

    @action pickAddress(result) {
        const location = coordinates(result);
        const picked = {};
        ADDRESS_FIELDS.forEach((field) => (picked[field] = result?.[field] ?? ''));
        // A new street starts without the old unit; the door contact and instructions stay.
        this.address = { ...this.address, ...picked, street2: '', location: location ?? this.address.location };
        this.addressQuery = result?.address ?? result?.street1 ?? '';
        this.selectedPlace = null;
        this.dropoffMoved();
    }

    @action pickSaved(place) {
        this.address = addressOf(place);
        this.addressQuery = place.address ?? place.street1 ?? '';
        this.selectedPlace = place;
        this.dropoffMoved();
    }

    @action setAddressField(field, event) {
        this.address = { ...this.address, [field]: event.target.value };
        this.requote.perform();
    }

    @action toggleSavePlace(checked) {
        this.savePlace = checked;
    }

    /** The pin went somewhere new: follow it on the map, re-measure and re-price. */
    dropoffMoved() {
        this.placePins(true);
        this.measure.perform();
        this.requote.perform();
    }

    /* The map: the pin is the drop-off, dragged to the exact entrance; a grey dot is where it was */

    @action drawMap(element) {
        const L = window.L;
        if (!L || !element) {
            return;
        }

        this.removeMap();
        const start = this.address.location ?? coordinates(this.order?.payload?.pickup);
        if (!start) {
            return;
        }

        const map = L.map(element, { zoomControl: false, attributionControl: false, scrollWheelZoom: false });
        L.tileLayer(TILE_URL, { maxZoom: 19 }).addTo(map);
        map.setView(start, 18);

        if (!this.dropoffLocked) {
            this.pin = L.marker(start, {
                draggable: true,
                keyboard: true,
                title: this.intl.t('storefront.order.edit.drag-pin'),
                icon: L.divIcon({ className: 'sfoe-pin-marker', html: '<span class="sfoe-pin"></span>', iconSize: [18, 18], iconAnchor: [9, 9] }),
            }).addTo(map);
            this.pin.on('dragend', () => {
                const { lat, lng } = this.pin.getLatLng();
                this.address = { ...this.address, location: [lat, lng] };
                this.selectedPlace = this.selectedPlaceUntouched ? this.selectedPlace : null;
                this.placePins(false);
                this.measure.perform();
                this.requote.perform();
            });
        } else {
            L.marker(start, { icon: L.divIcon({ className: 'sfoe-pin-marker', html: '<span class="sfoe-pin"></span>', iconSize: [18, 18], iconAnchor: [9, 9] }) }).addTo(map);
        }

        this.map = map;
        this.placePins(false);
    }

    placePins(recenter) {
        const L = window.L;
        const map = this.map;
        if (!L || !map) {
            return;
        }

        const now = this.address.location;
        const was = this.originalAddress?.location;
        if (now && this.pin) {
            this.pin.setLatLng(now);
        }

        this.wasPin?.remove();
        this.wasPin = null;
        if (was && now && !samePoint(was, now)) {
            this.wasPin = L.circleMarker(was, { radius: 6, color: '#fff', weight: 2, fillColor: '#9ca3af', fillOpacity: 1 }).addTo(map);
        }

        if (recenter && now) {
            was && !samePoint(was, now) ? map.fitBounds([was, now], { padding: [30, 30], maxZoom: 18 }) : map.setView(now, 18);
        }
    }

    @action removeMap() {
        this.map?.remove();
        this.map = null;
        this.pin = null;
        this.wasPin = null;
    }

    /** Road distance from the store to the new drop-off, for "Distance 2.1 km → 2.3 km". */
    @task({ restartable: true }) *measure() {
        yield timeout(400);
        const from = coordinates(this.order?.payload?.pickup);
        const to = this.address.location;
        if (!from || !to || !this.dropoffChanged) {
            this.newDistance = null;
            return;
        }

        try {
            const response = yield fetch(`${getRoutingHost(this.order?.payload)}/route/v1/driving/${from[1]},${from[0]};${to[1]},${to[0]}?overview=false`);
            const json = response.ok ? yield response.json() : null;
            this.newDistance = json?.routes?.[0]?.distance ?? null;
        } catch {
            this.newDistance = null;
        }
    }

    get distanceLine() {
        if (!this.dropoffChanged) {
            return null;
        }

        const parts = [];
        const was = this.overview?.delivery?.distance_m ?? this.order?.distance;
        if (was && this.newDistance) {
            parts.push(this.intl.t('storefront.order.edit.distance', { was: kilometres(was), now: kilometres(this.newDistance) }));
        } else if (this.newDistance) {
            parts.push(this.intl.t('storefront.order.edit.distance-now', { now: kilometres(this.newDistance) }));
        }

        const fee = this.preview?.order?.delivery_fee;
        const newFee = this.preview?.proposed?.delivery_fee;
        if (fee !== undefined && newFee !== undefined) {
            parts.push(
                fee === newFee
                    ? this.intl.t('storefront.order.edit.fee-unchanged', { fee: formatCurrency(fee, this.currency) })
                    : this.intl.t('storefront.order.edit.fee-changed', { was: formatCurrency(fee, this.currency), now: formatCurrency(newFee, this.currency) })
            );
        }

        return parts.join(' · ') || null;
    }

    /* Contact */

    @action setContact(field, event) {
        this.contact = { ...this.contact, [field]: event.target.value };
        this.requote.perform();
    }

    @action setContactScope(scope) {
        this.contactScope = scope;
    }

    get contactChanged() {
        return ['name', 'phone', 'email'].some((field) => (this.contact[field] ?? '') !== (this.originalContact[field] ?? ''));
    }

    @action openCustomer() {
        const id = this.order?.customer?.public_id;
        if (id) {
            this.hostRouter.transitionTo('console.storefront.customers.index.view', id);
        }
    }

    /* Items: quantity, options, remove with undo, substitute what is out of stock, add from the catalog */

    productFor(line) {
        return line.product ?? this.products.find((product) => product.public_id === line.productId) ?? null;
    }

    get lineRows() {
        return this.lines.map((line) => {
            const product = this.productFor(line);
            const substituteFor = line.substitutes ? this.lines.find((item) => item.entity === line.substitutes) : null;

            return {
                ...line,
                total: line.unitPrice * line.quantity,
                options: [...(line.variants ?? []).map((option) => option.name), ...(line.addons ?? []).map((addon) => `+ ${addon.name}`)].filter(Boolean),
                quantityChanged: !line.isNew && line.quantity !== line.originalQuantity,
                isChanged: !line.isNew && !line.removed && (line.quantity !== line.originalQuantity || line.optionsChanged),
                substituteFor: substituteFor?.name ?? null,
                canChangeOptions:
                    !line.removed && !this.itemsLocked && !this.itemsRemovalOnly && Boolean(product && ((product.variants?.length ?? 0) > 0 || (product.addon_categories?.length ?? 0) > 0)),
                canSubstitute: !line.removed && !line.isNew && line.unavailable && this.canAddItems,
                canIncrease: !this.itemsLocked && !line.removed && (!this.itemsRemovalOnly || line.isNew || line.quantity < line.originalQuantity),
                canDecrease: !this.itemsLocked && !line.removed && line.quantity > 1,
            };
        });
    }

    get canRemoveItems() {
        return !this.itemsLocked;
    }

    get canAddItems() {
        return !this.itemsLocked && !this.itemsRemovalOnly && this.products.length > 0;
    }

    @action changeQuantity(line, delta) {
        this.lines = this.lines.map((item) => (item.key === line.key ? { ...item, quantity: Math.max(1, item.quantity + delta) } : item));
        this.requote.perform();
    }

    @action removeLine(line) {
        this.lines = line.isNew ? this.lines.filter((item) => item.key !== line.key) : this.lines.map((item) => (item.key === line.key ? { ...item, removed: true } : item));
        this.requote.perform();
    }

    /** Bringing an item back also drops what was added in its place. */
    @action undoRemove(line) {
        this.lines = this.lines
            .filter((item) => !(item.isNew && line.entity && item.substitutes === line.entity))
            .map((item) => (item.key === line.key ? { ...item, removed: false, quantity: item.originalQuantity } : item));
        this.requote.perform();
    }

    @action openPicker() {
        this.picker = { mode: 'add' };
    }

    @action openSubstitute(line) {
        this.picker = { mode: 'substitute', line };
    }

    @action openOptions(line) {
        this.picker = { mode: 'options', line, product: this.productFor(line) };
    }

    @action closePicker() {
        this.picker = null;
    }

    get pickerProducts() {
        return this.picker?.mode === 'options' && this.picker.product ? [this.picker.product] : this.products;
    }

    @action addLine(product, quantity, variants, addons) {
        const base = cents(product.is_on_sale && product.sale_price ? product.sale_price : product.price);
        const unitPrice =
            base +
            variants.reduce((sum, option) => sum + cents(option.additional_cost), 0) +
            addons.reduce((sum, addon) => sum + cents(addon.is_on_sale && addon.sale_price ? addon.sale_price : addon.price), 0);
        const chosenVariants = variants.map((option) => ({
            id: option.public_id ?? option.id,
            name: option.name,
            additional_cost: option.additional_cost,
            product_variant_uuid: option.product_variant_uuid,
        }));
        const chosenAddons = addons.map((addon) => ({
            id: addon.public_id ?? addon.id,
            name: addon.name,
            price: addon.price,
            sale_price: addon.sale_price,
            is_on_sale: addon.is_on_sale,
            category_uuid: addon.category_uuid,
        }));
        const picker = this.picker;

        if (picker?.mode === 'options') {
            this.lines = this.lines.map((item) =>
                item.key === picker.line.key ? { ...item, quantity, variants: chosenVariants, addons: chosenAddons, unitPrice, optionsChanged: !item.isNew, product } : item
            );
            this.picker = null;
            this.requote.perform();
            return;
        }

        const added = {
            key: `new:${product.id}:${Date.now()}`,
            entity: null,
            product,
            productId: product.public_id,
            name: product.name,
            image: product.primary_image_url,
            quantity,
            originalQuantity: 0,
            variants: chosenVariants,
            addons: chosenAddons,
            unitPrice,
            optionsChanged: false,
            removed: false,
            isNew: true,
            substitutes: null,
        };

        if (picker?.mode === 'substitute') {
            added.substitutes = picker.line.entity;
            this.lines = [...this.lines.map((item) => (item.key === picker.line.key ? { ...item, removed: true } : item)), added];
            this.picker = null;
        } else {
            this.lines = [...this.lines, added];
        }
        this.requote.perform();
    }

    get itemsChanged() {
        return this.lines.some((line) => line.isNew || line.removed || line.optionsChanged || line.quantity !== line.originalQuantity);
    }

    /** "WKND15 (S$4.20 off) still applies: new subtotal S$80.00", or that a code no longer does. */
    get promotionNotes() {
        if (!this.itemsChanged || !this.preview) {
            return [];
        }

        const proposed = this.preview.proposed ?? {};
        const subtotal = formatCurrency(proposed.subtotal ?? 0, this.currency);
        const kept = (proposed.promotions ?? []).map((promotion) => ({
            tone: 'ok',
            text: this.intl.t('storefront.order.edit.promotion-applies', {
                promotion: promotion.code ?? promotion.name,
                amount: formatCurrency((promotion.amount ?? 0) + (promotion.delivery_amount ?? 0), this.currency),
                subtotal,
            }),
        }));
        const lost = (proposed.promotions_lost ?? []).map((code) => ({ tone: 'warn', text: this.intl.t('storefront.order.edit.promotion-lost', { promotion: code }) }));

        return [...lost, ...kept];
    }

    /* When */

    get dueLabel() {
        const due = this.overview?.due_at ? parseISO(this.overview.due_at) : null;
        return due && isValid(due) ? this.intl.t('storefront.order.edit.due', { time: format(due, 'HH:mm') }) : this.intl.t('storefront.order.edit.asap-help');
    }

    get hoursLabel() {
        const windows = this.overview?.edit?.hours?.windows;
        if (!Array.isArray(windows)) {
            return this.intl.t('storefront.order.edit.schedule-help');
        }
        if (!windows.length) {
            return this.intl.t('storefront.order.edit.closed-today');
        }

        // Store hours are wall-clock times in the store's own zone: shown as the store set them.
        const closes = windows[windows.length - 1].closes;
        return closes ? this.intl.t('storefront.order.edit.open-until', { time: closes }) : this.intl.t('storefront.order.edit.schedule-help');
    }

    @action setTiming(id) {
        this.isScheduled = id === 'schedule';
        if (!this.isScheduled) {
            this.scheduledAt = null;
        }
        this.requote.perform();
    }

    @action setScheduledAt(value) {
        this.scheduledAt = value;
        this.requote.perform();
    }

    @action setNotes(event) {
        this.notes = event.target.value;
        this.requote.perform();
    }

    get scheduleChanged() {
        const was = this.order?.scheduled_at ? new Date(this.order.scheduled_at).getTime() : null;
        const now = this.isScheduled && this.scheduledAt ? new Date(this.scheduledAt).getTime() : null;

        return was !== now;
    }

    /* Saving */

    get dropoffInput() {
        if (this.selectedPlaceUntouched) {
            return { place: this.selectedPlace.public_id ?? this.selectedPlace.id };
        }

        const input = {};
        ADDRESS_FIELDS.forEach((field) => {
            if (this.address[field]) {
                input[field] = this.address[field];
            }
        });
        if (this.address.phone) {
            input.phone = this.address.phone;
        }
        if (this.address.instructions) {
            input.instructions = this.address.instructions;
        }
        if (this.address.location) {
            input.location = { type: 'Point', coordinates: [this.address.location[1], this.address.location[0]] };
        }

        return input;
    }

    get input() {
        const input = {};

        if (!this.isPickup && this.dropoffChanged) {
            input.dropoff = this.dropoffInput;
            if (this.canSavePlace && this.savePlace) {
                input.save_place_to_customer = true;
            }
        }
        if (this.contactChanged) {
            input.contact = { ...this.contact, scope: this.contactScope };
        }
        if (this.itemsChanged) {
            input.items = this.lines
                .filter((line) => !line.removed)
                .map((line) => {
                    if (line.isNew) {
                        return {
                            entity: null,
                            product: line.product.public_id,
                            quantity: line.quantity,
                            variants: line.variants,
                            addons: line.addons,
                            substitutes: line.substitutes ?? null,
                        };
                    }

                    return line.optionsChanged
                        ? { entity: line.entity, quantity: line.quantity, variants: line.variants, addons: line.addons }
                        : { entity: line.entity, quantity: line.quantity };
                });
        }
        if (this.scheduleChanged) {
            input.scheduled_at = this.isScheduled && this.scheduledAt ? new Date(this.scheduledAt).toISOString() : null;
        }
        if ((this.notes ?? '').trim() !== (this.order?.notes ?? '').trim()) {
            input.notes = this.notes;
        }

        return input;
    }

    get hasChanges() {
        return Object.keys(this.input).length > 0;
    }

    @task({ restartable: true }) *requote() {
        yield timeout(350);

        if (!this.hasChanges) {
            this.preview = null;
            this.previewError = null;
            return;
        }

        try {
            this.preview = yield this.fetch.post(`orders/${this.order.public_id}/edit/preview`, this.input, { namespace: 'storefront/int/v1' });
            this.previewError = null;
            if (this.settlement && !(this.preview?.settlement?.options ?? []).includes(this.settlement)) {
                this.settlement = null;
            }
        } catch (error) {
            this.preview = null;
            this.previewError = error?.message ?? String(error);
        }
    }

    get violations() {
        return Object.values(this.preview?.violations ?? {});
    }

    get warnings() {
        return Object.values(this.preview?.warnings ?? {});
    }

    get difference() {
        return this.preview?.difference ?? 0;
    }

    get changeList() {
        return String(this.preview?.summary ?? '')
            .split(' · ')
            .map((part) => part.trim())
            .filter(Boolean);
    }

    get settlementAction() {
        return this.settlement ?? this.preview?.settlement?.action ?? 'none';
    }

    get settlementOptions() {
        const options = this.preview?.settlement?.options ?? [];
        const method = this.overview?.payment?.method_label ?? '';

        return options.length > 1 ? options.map((id) => ({ id, label: this.intl.t(`storefront.order.edit.settle.${id}`, { method }) })) : [];
    }

    get settlementSentence() {
        const amount = formatCurrency(Math.abs(this.difference), this.currency);
        const payment = this.overview?.payment;
        const method = payment?.method_label ?? '';
        if (this.difference === 0) {
            return null;
        }

        const outcome = this.intl.t(`storefront.order.edit.settle-sentence.${this.settlementAction}`, { amount, method });
        return payment?.amount_paid ? `${this.intl.t('storefront.order.edit.paid-by', { amount: formatCurrency(payment.amount_paid, this.currency), method })} ${outcome}` : outcome;
    }

    get hasDriver() {
        return Boolean(this.order?.driver_assigned_uuid ?? this.order?.driver_assigned);
    }

    get notifyDriverLabel() {
        const name = firstName(this.order?.driver_assigned?.name) || this.intl.t('storefront.order.edit.the-driver');
        if (this.dropoffChanged) {
            return this.intl.t('storefront.order.edit.notify-driver-dropoff', { name });
        }

        return this.itemsChanged ? this.intl.t('storefront.order.edit.notify-driver-items', { name }) : this.intl.t('storefront.order.edit.notify-driver', { name });
    }

    get canSave() {
        return this.hasChanges && this.preview && !this.violations.length && !this.requote.isRunning && !this.save.isRunning;
    }

    @action setSettlement(id) {
        this.settlement = id;
    }

    @action toggleNotifyCustomer(checked) {
        this.notifyCustomer = checked;
    }

    @action toggleNotifyDriver(checked) {
        this.notifyDriver = checked;
    }

    @task *save() {
        try {
            const response = yield this.fetch.patch(
                `orders/${this.order.public_id}/edit`,
                {
                    ...this.input,
                    confirm_total: this.preview?.proposed?.total,
                    settlement: this.settlementAction === 'none' ? null : this.settlementAction,
                    notify_customer: this.notifyCustomer,
                    notify_driver: this.notifyDriver,
                },
                { namespace: 'storefront/int/v1' }
            );
            const link = response?.settlement?.payment_link;
            this.notifications.success(
                link
                    ? this.intl.t(this.notifyCustomer ? 'storefront.order.edit.saved-link-sent' : 'storefront.order.edit.saved-link', {
                          id: this.order.public_id,
                          amount: formatCurrency(response.settlement.amount ?? this.difference, this.currency),
                          name: this.customerFirstName,
                      })
                    : this.intl.t('storefront.order.edit.saved', { id: this.order.public_id })
            );
            this.args.onSaved?.();
            if (link) {
                this.storefrontOrderActions.showPaymentLink(this.order, {
                    ...(this.overview?.payment ?? {}),
                    payment_link: link,
                    balance_due: (this.overview?.payment?.balance_due ?? 0) + this.difference,
                });
            }
        } catch (error) {
            this.notifications.serverError(error);
            this.requote.perform();
        }
    }

    @action discard() {
        this.reset();
        this.placePins(true);
        this.args.onCancel?.();
    }

    /** Opened from an alert: scroll to the section it is about. */
    @action focusSection(element) {
        const focus = this.args.focus;
        if (!focus) {
            return;
        }

        const section = element.querySelector(`[data-edit-section="${focus}"]`);
        section?.scrollIntoView({ block: 'start' });
    }
}
