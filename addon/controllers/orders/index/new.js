import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import { isValid, parseISO } from 'date-fns';
import { formatInStore, storeOffset } from '@fleetbase/storefront-engine/utils/store-time';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import findContact from '@fleetbase/storefront-engine/utils/find-contact';

/** A place's [lat, lng], or null. */
function coordinates(place) {
    const lat = Number(place?.latitude ?? place?.location?.coordinates?.[1]);
    const lng = Number(place?.longitude ?? place?.location?.coordinates?.[0]);

    return Number.isFinite(lat) && Number.isFinite(lng) && (lat || lng) ? [lat, lng] : null;
}

/** Straight-line kilometres between two [lat, lng] points. */
function kilometres(a, b) {
    const rad = Math.PI / 180;
    const dLat = (b[0] - a[0]) * rad;
    const dLng = (b[1] - a[1]) * rad;
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(a[0] * rad) * Math.cos(b[0] * rad) * Math.sin(dLng / 2) ** 2;

    return 6371 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

function cents(value) {
    const parsed = parseFloat(value);

    return Number.isFinite(parsed) ? Math.round(parsed) : 0;
}

export default class OrdersIndexNewController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service hostRouter;
    @service storefront;
    @service storefrontOrderActions;
    @service modalsManager;
    @service store;
    // Not `customer`: the orders list behind the panel filters on that key.
    queryParams = [{ forCustomer: 'for_customer' }, 'booking'];
    @tracked forCustomer = null;
    /** A booking (`?booking=1`): the picker offers bookable services and the time is a free slot. */
    @tracked booking = null;

    @tracked activeStore = null;
    @tracked locations = [];
    @tracked products = [];
    /** Network context: the member stores the order can be placed with (empty in store context). */
    @tracked storeOptions = [];
    @tracked network = null;
    /** Where cancel and a placed order return to. */
    returnRoute = 'console.storefront.orders.index';
    returnModel = null;
    @tracked selectedCustomer = null;
    /** The address being typed for the customer, or null while the New address form is closed. */
    /** Whether the picker for a non-saved address is open again after one was chosen. */
    @tracked changingDropoff = false;
    @tracked isPickup = false;
    @tracked isScheduled = false;
    @tracked scheduledAt = null;
    @tracked scheduledEnd = null;
    @tracked pickupLocation = null;
    /** A booking's provider: one of the company's drivers, or none to assign when it is confirmed. */
    @tracked provider = null;
    @tracked dropoff = null;
    @tracked lines = [];
    @tracked notes = '';
    @tracked promoCode = '';
    @tracked appliedPromoCode = null;
    @tracked payment = 'cash';
    @tracked tipMode = 'none';
    @tracked tipCustom = 0;
    @tracked quote = null;
    @tracked quoteError = null;
    @tracked showPicker = false;

    tipModes = ['none', 'fixed', 'percent-10', 'percent-20', 'percent-30', 'custom'];

    reset() {
        this.selectedCustomer = null;
        this.changingDropoff = false;
        this.loadedPlaces = null;
        this.isPickup = false;
        this.isScheduled = false;
        this.scheduledAt = null;
        this.scheduledEnd = null;
        this.provider = null;
        this.dropoff = null;
        this.lines = [];
        this.notes = '';
        this.promoCode = '';
        this.appliedPromoCode = null;
        this.payment = 'cash';
        this.tipMode = 'none';
        this.tipCustom = 0;
        this.quote = null;
        this.quoteError = null;
    }

    /** A time on the store's clock (date-fns pattern): bookings are made in the store's time. */
    storeTime(value, pattern) {
        return formatInStore(value, pattern, storeOffset(this.activeStore));
    }

    get isNetworkContext() {
        return this.storeOptions.length > 0;
    }

    /**
     * Step numbers shift by one in network context, where picking the store comes first.
     */
    get stepNumbers() {
        const offset = this.isNetworkContext ? 1 : 0;

        // A booking picks its services first; their time and place follow.
        return this.isBooking
            ? { store: 1, customer: 1 + offset, items: 2 + offset, fulfillment: 3 + offset, provider: 4 + offset }
            : { store: 1, customer: 1 + offset, fulfillment: 2 + offset, items: 3 + offset };
    }

    get storeOptionRows() {
        return this.storeOptions.map((store) => ({ store, isSelected: store === this.activeStore }));
    }

    /**
     * Network context: picking the store loads its locations and products; lines are
     * cleared because they belong to the previous store's catalog.
     */
    @task({ restartable: true }) *selectStore(store) {
        this.activeStore = store;
        this.lines = [];
        this.quote = null;
        this.quoteError = null;
        this.locations = [];
        this.products = [];
        this.pickupLocation = null;

        if (!store) {
            return;
        }

        const [locations, products] = yield Promise.all([
            this.store.query('store-location', { store: store.id, limit: -1 }).catch(() => []),
            this.store.query('product', { store_uuid: store.id, limit: -1, status: 'published' }).catch(() => []),
        ]);

        this.locations = locations?.toArray?.() ?? Array.from(locations ?? []);
        this.products = products?.toArray?.() ?? Array.from(products ?? []);
        this.pickupLocation = this.locations[0] ?? null;
    }

    returnToOrders() {
        return this.returnModel ? this.hostRouter.transitionTo(this.returnRoute, this.returnModel) : this.hostRouter.transitionTo(this.returnRoute);
    }

    get currency() {
        return this.quote?.currency ?? this.activeStore?.currency ?? 'USD';
    }

    /** Places loaded for the selected customer; the customer list payload does not carry them. */
    @tracked loadedPlaces = null;

    get customerPlaces() {
        const places = this.loadedPlaces ?? this.selectedCustomer?.places;

        return places?.toArray?.() ?? Array.from(places ?? []);
    }

    get defaultPlaceId() {
        return this.selectedCustomer?.place_uuid ?? this.selectedCustomer?.place?.id ?? null;
    }

    /** Whether the product picker is on screen: opened by Add products, or while the order is still empty. */
    /** Console orders are cash on delivery or already paid; cards are collected in the app. */
    get paymentOptions() {
        return [
            { value: 'cash', label: this.isBooking ? this.intl.t('storefront.orders.booking.payment-after') : this.intl.t('storefront.orders.create.payment-cash') },
            { value: 'paid', label: this.isBooking ? this.intl.t('storefront.orders.booking.payment-paid') : this.intl.t('storefront.orders.create.payment-paid') },
        ];
    }

    get isBooking() {
        return Boolean(this.booking) || Boolean(this.bookableLine);
    }

    /** The first line that books a service; its slots set the order's time. */
    get bookableLine() {
        return this.lines.find((line) => line.product?.is_bookable) ?? null;
    }

    /** In a booking, the picker offers the store's bookable services first, then the products the provider can bring. */
    get pickerProducts() {
        if (!this.booking) {
            return this.products;
        }

        return [...this.products.filter((product) => product.is_bookable), ...this.products.filter((product) => !product.is_bookable)];
    }

    @action selectSlot(start, end) {
        this.isScheduled = true;
        this.scheduledAt = start;
        this.scheduledEnd = end ?? null;
        this.requote.perform();
    }

    /** Whether a line books a service (rather than a product brought along). */
    isServiceLine(line) {
        return Boolean(line.product?.is_bookable || line.product?.is_service);
    }

    serviceLength(product) {
        const minutes = Number(product?.meta?.service?.duration_minutes) || 0;
        if (!minutes) {
            return null;
        }

        return minutes % 60 === 0 ? this.intl.t('storefront.orders.booking.hours', { count: minutes / 60 }) : this.intl.t('storefront.orders.booking.minutes', { count: minutes });
    }

    /** The booking at a glance: "Tue 14 Oct · 10:00 to 13:00". */
    get bookingWhen() {
        const start = this.scheduledAt ? parseISO(this.scheduledAt) : null;
        if (!start || !isValid(start)) {
            return null;
        }

        const end = this.scheduledEnd ? parseISO(this.scheduledEnd) : null;
        return `${this.storeTime(start, 'EEE d MMM')} · ${end && isValid(end) ? this.intl.t('storefront.orders.booking.range', { start: this.storeTime(start, 'HH:mm'), end: this.storeTime(end, 'HH:mm') }) : this.storeTime(start, 'HH:mm')}`;
    }

    get bookingWhere() {
        const where = this.isPickup
            ? this.intl.t('storefront.orders.booking.at-store-short', { store: this.pickupLocation?.name ?? this.activeStore?.name ?? '' })
            : this.dropoff
              ? (this.dropoff.name ?? this.dropoff.address)
              : null;

        return [this.provider?.name ?? this.intl.t('storefront.orders.booking.any-provider'), where].filter(Boolean).join(' · ');
    }

    @action setProvider(driver) {
        this.provider = driver ?? null;
    }

    /** "Book Tue 10:00 · ₮35,000". */
    get bookLabel() {
        const start = this.scheduledAt ? parseISO(this.scheduledAt) : null;
        const total = formatCurrency(this.totals.total ?? 0, this.currency);

        return start && isValid(start)
            ? this.intl.t('storefront.orders.booking.book-at', { when: this.storeTime(start, 'EEE HH:mm'), total })
            : this.intl.t('storefront.orders.booking.book', { total });
    }

    /** What the booking costs, split as the design shows it: services, products brought along, travel. */
    get bookingSplit() {
        const rows = this.lineRows;
        const services = rows.filter((line) => line.isService);
        const products = rows.filter((line) => !line.isService);

        return {
            services: services.map((line) => ({ label: [line.product.name, this.serviceLength(line.product)].filter(Boolean).join(' · '), total: line.total })),
            productsTotal: products.reduce((sum, line) => sum + line.total, 0),
            productsCount: products.reduce((sum, line) => sum + line.quantity, 0),
        };
    }

    /** How far the service address is from the store the provider leaves from. */
    distanceTo(place) {
        const from = coordinates(this.pickupLocation?.place);
        const to = coordinates(place);
        return from && to ? kilometres(from, to) : null;
    }

    get travelDistance() {
        const km = this.isPickup ? null : this.distanceTo(this.dropoff);
        return km === null ? null : `${km.toFixed(1)} km`;
    }

    get pickerVisible() {
        return this.showPicker || this.lines.length === 0;
    }

    @action openPicker() {
        this.showPicker = true;
    }

    @action closePicker() {
        this.showPicker = false;
    }

    /** The store locations as selectable cards. */
    get locationRows() {
        return this.locations.map((location) => ({ location, isSelected: location === this.pickupLocation }));
    }

    /** The dropoff when it is not one of the customer's saved places (those show as tiles). */
    get otherDropoff() {
        const dropoff = this.dropoff;
        return dropoff && !this.customerPlaces.some((place) => place.id === dropoff.id) ? dropoff : null;
    }

    get placeRows() {
        return this.customerPlaces.map((place) => {
            const km = this.isBooking ? this.distanceTo(place) : null;

            return { place, isDefault: place.id === this.defaultPlaceId, isSelected: this.dropoff?.id === place.id, distance: km === null ? null : `${km.toFixed(1)} km` };
        });
    }

    get itemsCount() {
        return this.lines.reduce((sum, line) => sum + line.quantity, 0);
    }

    get linesSubtotal() {
        return this.lines.reduce((sum, line) => sum + this.lineTotal(line), 0);
    }

    lineUnitPrice(line) {
        const product = line.product;
        const base = cents(product.is_on_sale && product.sale_price ? product.sale_price : product.price);
        const variants = line.variants.reduce((sum, option) => sum + cents(option.additional_cost), 0);
        const addons = line.addons.reduce((sum, addon) => sum + cents(addon.is_on_sale && addon.sale_price ? addon.sale_price : addon.price), 0);

        return base + variants + addons;
    }

    lineTotal(line) {
        return this.lineUnitPrice(line) * line.quantity;
    }

    get lineRows() {
        return this.lines.map((line) => ({
            ...line,
            unitPrice: this.lineUnitPrice(line),
            total: this.lineTotal(line),
            optionsLabel: [...line.variants.map((option) => option.name), ...line.addons.map((addon) => `+ ${addon.name}`)].join(' · '),
            unavailable: line.product.is_available === false,
            isService: this.isServiceLine(line),
            length: this.serviceLength(line.product),
        }));
    }

    get tipAmount() {
        switch (this.tipMode) {
            case 'fixed':
                return 350;
            case 'percent':
            case 'percent-10':
                return Math.round(this.linesSubtotal * 0.1);
            case 'percent-20':
                return Math.round(this.linesSubtotal * 0.2);
            case 'percent-30':
                return Math.round(this.linesSubtotal * 0.3);
            case 'custom':
                return cents(this.tipCustom);
            default:
                return 0;
        }
    }

    get totals() {
        return (
            this.quote?.totals ?? {
                subtotal: this.linesSubtotal,
                delivery_fee: 0,
                discount: 0,
                tip: this.tipAmount,
                delivery_tip: 0,
                total: this.linesSubtotal + this.tipAmount,
                items: this.itemsCount,
            }
        );
    }

    get minimum() {
        return this.quote?.minimum ?? { required: false, met: true };
    }

    get issues() {
        const issues = [];

        if (this.isNetworkContext && !this.activeStore) {
            issues.push(this.intl.t('storefront.orders.create.issues.store'));
        }

        if (!this.selectedCustomer) {
            issues.push(this.intl.t('storefront.orders.create.issues.customer'));
        }

        if (this.bookableLine && !this.scheduledAt) {
            issues.push(this.intl.t('storefront.orders.booking.pick-slot'));
        }

        if (this.isBooking && !this.bookableLine) {
            issues.push(this.intl.t('storefront.orders.booking.issues.service'));
        } else if (!this.lines.length) {
            issues.push(this.intl.t('storefront.orders.create.issues.items'));
        }

        if (!this.isPickup && !this.dropoff) {
            issues.push(this.intl.t(this.isBooking ? 'storefront.orders.booking.issues.address' : 'storefront.orders.create.issues.dropoff'));
        }

        if (!this.pickupLocation) {
            issues.push(this.intl.t('storefront.orders.create.issues.location'));
        }

        if (this.quote && !this.minimum.met) {
            issues.push(
                this.intl.t('storefront.orders.create.issues.minimum', { amount: this.intl.formatNumber((this.minimum.amount ?? 0) / 100, { style: 'currency', currency: this.currency }) })
            );
        }

        // The API repeats the missing-dropoff / missing-location checks above; only surface its error once those are satisfied.
        if (this.quoteError && !this.isPickup && this.dropoff && this.pickupLocation) {
            issues.push(this.quoteError);
        }

        return issues;
    }

    get canPlace() {
        return this.issues.length === 0 && this.quote && !this.requote.isRunning && !this.place.isRunning;
    }

    get payload() {
        return {
            store: this.activeStore?.id,
            customer: this.selectedCustomer?.id,
            is_pickup: this.isPickup,
            pickup_location: this.pickupLocation?.id,
            dropoff: this.isPickup ? null : this.dropoff?.id,
            scheduled_at: this.isScheduled ? this.scheduledAt : null,
            items: this.lines.map((line) => ({
                product: line.product.public_id,
                quantity: line.quantity,
                variants: line.variants.map((option) => ({
                    id: option.public_id ?? option.id,
                    name: option.name,
                    additional_cost: option.additional_cost,
                    product_variant_uuid: option.product_variant_uuid,
                })),
                addons: line.addons.map((addon) => ({
                    id: addon.public_id ?? addon.id,
                    name: addon.name,
                    price: addon.price,
                    sale_price: addon.sale_price,
                    is_on_sale: addon.is_on_sale,
                    category_uuid: addon.category_uuid,
                })),
            })),
            tip: this.tipAmount || 0,
            delivery_tip: 0,
            promo_code: this.appliedPromoCode,
            notes: this.notes,
            payment: this.payment,
        };
    }

    /**
     * Re-price through the API whenever something that changes the total changes.
     */
    @task({ restartable: true }) *requote() {
        if (!this.lines.length || !this.activeStore) {
            this.quote = null;
            this.quoteError = null;
            return;
        }

        yield timeout(350);

        try {
            this.quote = yield this.fetch.post('orders/console/quote', this.payload, { namespace: 'storefront/int/v1' });
            this.quoteError = this.quote?.quote_error ?? null;

            if (this.quote?.promotions?.rejected?.length) {
                const rejected = this.quote.promotions.rejected[0];
                this.notifications.warning(this.intl.t('storefront.orders.create.promo-rejected', { code: rejected.code, reason: rejected.reason }));
                this.appliedPromoCode = null;
            }
        } catch (error) {
            this.quote = null;
            this.quoteError = error?.message ?? String(error);
        }
    }

    @task *place() {
        try {
            const order = yield this.fetch.post(
                'orders/console/place',
                { ...this.payload, cart: this.quote?.cart, service_quote: this.quote?.service_quote },
                { namespace: 'storefront/int/v1', normalizeToEmberData: true, normalizeModelType: 'order' }
            );
            // A booking's provider is its driver: assigned the way the Assign driver dialog does.
            if (order && this.isBooking && this.provider) {
                try {
                    yield this.storefrontOrderActions.setDriver(order, this.provider);
                } catch (error) {
                    this.notifications.warning(this.intl.t('storefront.orders.booking.provider-not-assigned', { name: this.provider.name }));
                }
            }
            this.notifications.success(this.intl.t('storefront.orders.create.placed', { id: order?.public_id ?? '' }));
            yield this.returnToOrders();
            this.hostRouter.refresh();

            if (order) {
                this.storefrontOrderActions.viewOrder(order);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** Adds a customer on the spot and puts the order in their name. */
    @action newCustomer() {
        this.storefront.createCustomer({ onSaved: (customer) => this.selectCustomer(customer) });
    }

    @action selectCustomer(customer) {
        this.selectedCustomer = customer;
        this.changingDropoff = false;
        this.loadedPlaces = null;
        if (customer) {
            this.loadCustomerPlaces.perform(customer);
        }
        this.dropoff = this.customerPlaces.find((place) => place.id === this.defaultPlaceId) ?? this.customerPlaces[0] ?? null;
        this.requote.perform();
    }

    /**
     * The customer's saved places come with the contact record (the customers list leaves
     * them out); the default place becomes the dropoff when none is chosen yet.
     */
    @task({ restartable: true }) *loadCustomerPlaces(customer) {
        try {
            const contact = yield findContact(this.store, customer.id ?? customer.public_id);
            const places = yield contact.places;
            if (this.selectedCustomer !== customer) {
                return;
            }

            this.loadedPlaces = places?.toArray?.() ?? Array.from(places ?? []);
            if (!this.dropoff) {
                const defaultId = contact.place_uuid ?? this.defaultPlaceId;
                this.dropoff = this.loadedPlaces.find((place) => place.id === defaultId) ?? this.loadedPlaces[0] ?? null;
                if (this.dropoff) {
                    this.requote.perform();
                }
            }
        } catch {
            // Keep whatever the customer record carried.
        }
    }

    /**
     * Opens the address form; the saved address becomes one of the customer's places and this
     * order's dropoff.
     */
    @action openNewAddress() {
        return this.storefront.addCustomerAddress(this.selectedCustomer, {
            onSaved: (place) => {
                this.loadedPlaces = [...this.customerPlaces.filter((item) => item.id !== place.id), place];
                this.selectDropoff(place);
            },
        });
    }

    @action changeDropoff() {
        this.changingDropoff = true;
    }

    @action setFulfillment(id) {
        return this.setPickup(id === 'pickup');
    }

    @action setTiming(id) {
        return this.setScheduled(id === 'schedule');
    }

    @action setPickup(isPickup) {
        this.isPickup = isPickup;
        this.requote.perform();
    }

    @action setScheduled(isScheduled) {
        this.isScheduled = isScheduled;

        if (!isScheduled) {
            this.scheduledAt = null;
        }

        this.requote.perform();
    }

    @action setScheduledAt(value) {
        this.scheduledAt = value;
        this.requote.perform();
    }

    @action selectLocation(location) {
        this.pickupLocation = location;
        this.requote.perform();
    }

    @action selectDropoff(place) {
        this.dropoff = place;
        this.changingDropoff = false;
        this.requote.perform();
    }

    @action addLine(product, quantity, variants, addons) {
        const key = `${product.id}:${variants.map((option) => option.id).join(',')}:${addons.map((addon) => addon.id).join(',')}`;
        const existing = this.lines.find((line) => line.key === key);

        if (existing) {
            this.lines = this.lines.map((line) => (line.key === key ? { ...line, quantity: line.quantity + quantity } : line));
        } else {
            this.lines = [...this.lines, { key, product, quantity, variants, addons }];
        }

        this.requote.perform();
    }

    @action changeQuantity(line, delta) {
        this.lines = this.lines.map((item) => (item.key === line.key ? { ...item, quantity: Math.max(1, item.quantity + delta) } : item));
        this.requote.perform();
    }

    @action removeLine(line) {
        this.lines = this.lines.filter((item) => item.key !== line.key);
        this.requote.perform();
    }

    @action setNotes(event) {
        this.notes = event.target.value;
    }

    @action setPromoCode(event) {
        this.promoCode = event.target.value;
    }

    @action applyPromoCode(event) {
        event?.preventDefault?.();
        this.appliedPromoCode = this.promoCode.trim() || null;
        this.requote.perform();
    }

    @action clearPromoCode() {
        this.promoCode = '';
        this.appliedPromoCode = null;
        this.requote.perform();
    }

    @action setPayment(payment) {
        this.payment = payment;
    }

    @action setTipMode(mode) {
        this.tipMode = mode;
        this.requote.perform();
    }

    @action setTipCustom(value) {
        this.tipCustom = value;
        this.tipMode = 'custom';
        this.requote.perform();
    }

    @action cancel() {
        return this.returnToOrders();
    }
}
