import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';

function cents(value) {
    const parsed = parseFloat(value);

    return Number.isFinite(parsed) ? Math.round(parsed) : 0;
}

function toArray(value) {
    return value?.toArray?.() ?? Array.from(value ?? []);
}

/**
 * Edit a placed order: the drop-off, the contact, the items, the time and the notes. Every change
 * is priced by the server (the same math as checkout) before it is saved; the footer shows the new
 * total, how the difference is settled and who is told. What the order's stage locks stays visible
 * and says why.
 */
export default class StorefrontOrderEditFormComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service store;
    @service storefront;

    @tracked overview = null;
    @tracked preview = null;
    @tracked previewError = null;
    @tracked products = [];
    @tracked places = [];
    @tracked customer = null;
    @tracked dropoff = null;
    @tracked contact = {};
    @tracked contactScope = 'order';
    @tracked lines = [];
    @tracked isScheduled = false;
    @tracked scheduledAt = null;
    @tracked notes = '';
    @tracked settlement = null;
    @tracked notifyCustomer = true;
    @tracked notifyDriver = true;
    @tracked showPicker = false;

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

    reset() {
        const order = this.order;
        const customer = order?.customer;

        this.lines = toArray(order?.payload?.entities).map((entity) => {
            const quantity = Number(entity.meta?.quantity) || 1;
            const subtotal = cents(entity.meta?.subtotal);

            return {
                key: entity.public_id,
                entity: entity.public_id,
                name: entity.name,
                image: entity.photo_url ?? entity.meta?.image_url,
                quantity,
                originalQuantity: quantity,
                variants: entity.meta?.variants ?? [],
                addons: entity.meta?.addons ?? [],
                unitPrice: Math.round(subtotal / quantity),
                unavailable: Boolean(entity.meta?.unavailable),
                removed: false,
                isNew: false,
            };
        });
        this.contact = { name: customer?.name ?? '', phone: customer?.phone ?? '', email: customer?.email ?? '' };
        this.originalContact = { ...this.contact };
        this.dropoff = null;
        this.isScheduled = Boolean(order?.scheduled_at);
        this.scheduledAt = order?.scheduled_at ?? null;
        this.notes = order?.notes ?? '';
        this.settlement = null;
        this.preview = null;
    }

    @task *load() {
        const order = this.order;
        try {
            this.overview = yield this.fetch.get(`orders/${order.public_id}/overview`, {}, { namespace: 'storefront/int/v1' });
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

    get scheduleLocked() {
        return this.lockFor('schedule')?.rule === 'locked' || this.isClosed;
    }

    get stageNote() {
        const stage = this.overview?.edit?.stage;
        return stage ? this.intl.t(`storefront.order.edit.stage.${stage}`) : null;
    }

    /* Drop-off */

    get currentDropoff() {
        return this.order?.payload?.dropoff;
    }

    get placeRows() {
        const selected = this.dropoff ?? this.currentDropoff;

        return this.places.map((place) => ({ place, isSelected: selected?.id === place.id }));
    }

    get otherDropoff() {
        const dropoff = this.dropoff;
        return dropoff && !this.places.some((place) => place.id === dropoff.id) ? dropoff : null;
    }

    @action selectDropoff(place) {
        this.dropoff = place?.id === this.currentDropoff?.id ? null : place;
        this.requote.perform();
    }

    @action newAddress() {
        return this.storefront.addCustomerAddress(this.customer, {
            onSaved: (place) => {
                this.places = [...this.places.filter((item) => item.id !== place.id), place];
                this.selectDropoff(place);
            },
        });
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

    /* Items */

    get lineRows() {
        return this.lines.map((line) => ({
            ...line,
            total: line.unitPrice * line.quantity,
            optionsLabel: [...(line.variants ?? []).map((option) => option.name), ...(line.addons ?? []).map((addon) => `+ ${addon.name}`)].filter(Boolean).join(' · '),
            quantityChanged: !line.isNew && line.quantity !== line.originalQuantity,
            canIncrease: !this.itemsLocked && !line.removed && (!this.itemsRemovalOnly || line.isNew || line.quantity < line.originalQuantity),
            canDecrease: !this.itemsLocked && !line.removed && line.quantity > 1,
        }));
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

    @action undoRemove(line) {
        this.lines = this.lines.map((item) => (item.key === line.key ? { ...item, removed: false, quantity: item.originalQuantity } : item));
        this.requote.perform();
    }

    @action openPicker() {
        this.showPicker = true;
    }

    @action closePicker() {
        this.showPicker = false;
    }

    @action addLine(product, quantity, variants, addons) {
        const base = cents(product.is_on_sale && product.sale_price ? product.sale_price : product.price);
        const unitPrice =
            base +
            variants.reduce((sum, option) => sum + cents(option.additional_cost), 0) +
            addons.reduce((sum, addon) => sum + cents(addon.is_on_sale && addon.sale_price ? addon.sale_price : addon.price), 0);

        this.lines = [
            ...this.lines,
            {
                key: `new:${product.id}:${Date.now()}`,
                entity: null,
                product,
                name: product.name,
                image: product.primary_image_url,
                quantity,
                originalQuantity: 0,
                variants: variants.map((option) => ({
                    id: option.public_id ?? option.id,
                    name: option.name,
                    additional_cost: option.additional_cost,
                    product_variant_uuid: option.product_variant_uuid,
                })),
                addons: addons.map((addon) => ({
                    id: addon.public_id ?? addon.id,
                    name: addon.name,
                    price: addon.price,
                    sale_price: addon.sale_price,
                    is_on_sale: addon.is_on_sale,
                    category_uuid: addon.category_uuid,
                })),
                unitPrice,
                removed: false,
                isNew: true,
            },
        ];
        this.requote.perform();
    }

    get itemsChanged() {
        return this.lines.some((line) => line.isNew || line.removed || line.quantity !== line.originalQuantity);
    }

    /* Time and notes */

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

    get input() {
        const input = {};

        if (this.dropoff) {
            input.dropoff = { place: this.dropoff.public_id ?? this.dropoff.id };
        }
        if (this.contactChanged) {
            input.contact = { ...this.contact, scope: this.contactScope };
        }
        if (this.itemsChanged) {
            input.items = this.lines
                .filter((line) => !line.removed)
                .map((line) =>
                    line.isNew
                        ? { entity: null, product: line.product.public_id, quantity: line.quantity, variants: line.variants, addons: line.addons }
                        : { entity: line.entity, quantity: line.quantity }
                );
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

    get settlementAction() {
        return this.settlement ?? this.preview?.settlement?.action ?? 'none';
    }

    get settlementOptions() {
        const options = this.preview?.settlement?.options ?? [];

        return options.length > 1 ? options.map((id) => ({ id, label: this.intl.t(`storefront.order.edit.settle.${id}`) })) : [];
    }

    get settlementSentence() {
        const amount = formatCurrency(Math.abs(this.difference), this.currency);
        const method = this.overview?.payment?.method_label ?? '';

        return this.difference === 0 ? null : this.intl.t(`storefront.order.edit.settle-sentence.${this.settlementAction}`, { amount, method });
    }

    get hasDriver() {
        return Boolean(this.order?.driver_assigned_uuid ?? this.order?.driver_assigned);
    }

    get canSave() {
        return this.hasChanges && this.preview && !this.violations.length && !this.requote.isRunning && !this.save.isRunning;
    }

    @action setSettlement(id) {
        this.settlement = id;
    }

    @action toggleNotifyCustomer(event) {
        this.notifyCustomer = event.target.checked;
    }

    @action toggleNotifyDriver(event) {
        this.notifyDriver = event.target.checked;
    }

    @task *save() {
        try {
            yield this.fetch.patch(
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
            this.notifications.success(this.intl.t('storefront.order.edit.saved', { id: this.order.public_id }));
            this.args.onSaved?.();
        } catch (error) {
            this.notifications.serverError(error);
            this.requote.perform();
        }
    }

    @action discard() {
        this.reset();
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
