import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';

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
    queryParams = [{ customer: 'for_customer' }];
    @tracked customer = null;

    @tracked activeStore = null;
    @tracked locations = [];
    @tracked products = [];
    @tracked selectedCustomer = null;
    @tracked isPickup = false;
    @tracked isScheduled = false;
    @tracked scheduledAt = null;
    @tracked pickupLocation = null;
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
    @tracked pickerQuery = '';
    @tracked configuring = null;
    @tracked configVariants = {};
    @tracked configAddons = [];
    @tracked configQuantity = 1;

    paymentOptions = ['cash', 'paid'];
    tipModes = ['none', 'fixed', 'percent', 'custom'];

    reset() {
        this.selectedCustomer = null;
        this.isPickup = false;
        this.isScheduled = false;
        this.scheduledAt = null;
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
        this.pickerQuery = '';
        this.configuring = null;
    }

    get currency() {
        return this.quote?.currency ?? this.activeStore?.currency ?? 'USD';
    }

    get customerPlaces() {
        const places = this.selectedCustomer?.places;

        return places?.toArray?.() ?? Array.from(places ?? []);
    }

    get defaultPlaceId() {
        return this.selectedCustomer?.place_uuid ?? this.selectedCustomer?.place?.id ?? null;
    }

    get placeRows() {
        return this.customerPlaces.map((place) => ({ place, isDefault: place.id === this.defaultPlaceId, isSelected: this.dropoff?.id === place.id }));
    }

    get pickerProducts() {
        const query = this.pickerQuery.trim().toLowerCase();

        return this.products.filter((product) => !query || String(product.name ?? '').toLowerCase().includes(query) || String(product.sku ?? '').toLowerCase().includes(query));
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
        }));
    }

    get tipAmount() {
        switch (this.tipMode) {
            case 'fixed':
                return 350;
            case 'percent':
                return Math.round(this.linesSubtotal * 0.1);
            case 'custom':
                return cents(this.tipCustom);
            default:
                return 0;
        }
    }

    get totals() {
        return this.quote?.totals ?? { subtotal: this.linesSubtotal, delivery_fee: 0, discount: 0, tip: this.tipAmount, delivery_tip: 0, total: this.linesSubtotal + this.tipAmount, items: this.itemsCount };
    }

    get minimum() {
        return this.quote?.minimum ?? { required: false, met: true };
    }

    get issues() {
        const issues = [];

        if (!this.selectedCustomer) {
            issues.push(this.intl.t('storefront.orders.create.issues.customer'));
        }

        if (!this.lines.length) {
            issues.push(this.intl.t('storefront.orders.create.issues.items'));
        }

        if (!this.isPickup && !this.dropoff) {
            issues.push(this.intl.t('storefront.orders.create.issues.dropoff'));
        }

        if (!this.pickupLocation) {
            issues.push(this.intl.t('storefront.orders.create.issues.location'));
        }

        if (this.quote && !this.minimum.met) {
            issues.push(this.intl.t('storefront.orders.create.issues.minimum', { amount: this.intl.formatNumber((this.minimum.amount ?? 0) / 100, { style: 'currency', currency: this.currency }) }));
        }

        if (this.quoteError && !this.isPickup) {
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
                variants: line.variants.map((option) => ({ id: option.public_id ?? option.id, name: option.name, additional_cost: option.additional_cost, product_variant_uuid: option.product_variant_uuid })),
                addons: line.addons.map((addon) => ({ id: addon.public_id ?? addon.id, name: addon.name, price: addon.price, sale_price: addon.sale_price, is_on_sale: addon.is_on_sale, category_uuid: addon.category_uuid })),
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
            const order = yield this.fetch.post('orders/console/place', { ...this.payload, cart: this.quote?.cart, service_quote: this.quote?.service_quote }, { namespace: 'storefront/int/v1', normalizeToEmberData: true, normalizeModelType: 'order' });
            this.notifications.success(this.intl.t('storefront.orders.create.placed', { id: order?.public_id ?? '' }));
            yield this.hostRouter.transitionTo('console.storefront.orders.index');
            this.hostRouter.refresh();

            if (order) {
                this.storefrontOrderActions.viewOrder(order);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action selectCustomer(customer) {
        this.selectedCustomer = customer;
        this.dropoff = this.customerPlaces.find((place) => place.id === this.defaultPlaceId) ?? this.customerPlaces[0] ?? null;
        this.requote.perform();
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
        this.requote.perform();
    }

    @action setPickerQuery(event) {
        this.pickerQuery = event.target.value;
    }

    get configuringNeedsChoices() {
        const product = this.configuring;

        return Boolean(product && ((product.variants?.length ?? 0) > 0 || (product.addon_categories?.length ?? 0) > 0));
    }

    get configVariantRows() {
        const variants = this.configuring?.variants?.toArray?.() ?? Array.from(this.configuring?.variants ?? []);

        return variants.map((variant) => ({
            variant,
            options: (variant.options?.toArray?.() ?? Array.from(variant.options ?? [])).map((option) => ({ option, isSelected: (this.configVariants[variant.id] ?? []).some((selected) => selected.id === option.id) })),
        }));
    }

    get configAddonRows() {
        const categories = this.configuring?.addon_categories?.toArray?.() ?? Array.from(this.configuring?.addon_categories ?? []);

        return categories.map((category) => {
            const excluded = new Set((category.excluded_addons ?? []).map((id) => String(id)));
            const addons = (category.category?.addons?.toArray?.() ?? Array.from(category.category?.addons ?? [])).filter((addon) => !excluded.has(String(addon.id)));

            return { category, addons: addons.map((addon) => ({ addon, isSelected: this.configAddons.some((selected) => selected.id === addon.id) })) };
        });
    }

    @action pickProduct(product) {
        if ((product.variants?.length ?? 0) > 0 || (product.addon_categories?.length ?? 0) > 0) {
            this.configuring = product;
            this.configVariants = {};
            this.configAddons = [];
            this.configQuantity = 1;
            return;
        }

        this.addLine(product, 1, [], []);
    }

    @action toggleVariantOption(variant, option) {
        const current = this.configVariants[variant.id] ?? [];
        const exists = current.some((selected) => selected.id === option.id);
        let next;

        if (variant.is_multiselect) {
            next = exists ? current.filter((selected) => selected.id !== option.id) : [...current, option];
        } else {
            next = exists ? [] : [option];
        }

        this.configVariants = { ...this.configVariants, [variant.id]: next };
    }

    @action toggleAddon(category, addon) {
        const exists = this.configAddons.some((selected) => selected.id === addon.id);

        if (exists) {
            this.configAddons = this.configAddons.filter((selected) => selected.id !== addon.id);
            return;
        }

        const max = category.max_selectable ?? 0;
        const inCategory = this.configAddons.filter((selected) => selected.category_uuid === addon.category_uuid).length;

        if (max && inCategory >= max) {
            this.notifications.warning(this.intl.t('storefront.orders.create.max-addons', { max, category: category.name }));
            return;
        }

        this.configAddons = [...this.configAddons, addon];
    }

    @action setConfigQuantity(delta) {
        this.configQuantity = Math.max(1, this.configQuantity + delta);
    }

    @action confirmConfig() {
        const product = this.configuring;
        const missing = this.configVariantRows.filter((row) => row.variant.is_required && !(this.configVariants[row.variant.id] ?? []).length);

        if (missing.length) {
            this.notifications.warning(this.intl.t('storefront.orders.create.variant-required', { variant: missing[0].variant.name }));
            return;
        }

        this.addLine(product, this.configQuantity, Object.values(this.configVariants).flat(), this.configAddons);
        this.configuring = null;
    }

    @action cancelConfig() {
        this.configuring = null;
    }

    addLine(product, quantity, variants, addons) {
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
        return this.hostRouter.transitionTo('console.storefront.orders.index');
    }
}
