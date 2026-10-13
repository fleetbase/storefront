import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';

/**
 * Search a store's products and add one to an order, choosing its variants and add-ons first when
 * it has any. Used by the new order form and the order edit form.
 *
 * @argument {Array} products the store's products
 * @argument {String} currency for option prices
 * @argument {Function} onAdd called with (product, quantity, variants, addons)
 * @argument {Function} [onDone] shows a Done button that calls it
 */
export default class StorefrontOrderProductPickerComponent extends Component {
    @service intl;
    @service notifications;
    @tracked pickerQuery = '';
    @tracked configuring = null;
    @tracked configVariants = {};
    @tracked configAddons = [];
    @tracked configQuantity = 1;

    get currency() {
        return this.args.currency;
    }

    get pickerProducts() {
        const query = this.pickerQuery.trim().toLowerCase();

        return (this.args.products ?? []).filter(
            (product) =>
                !query ||
                String(product.name ?? '')
                    .toLowerCase()
                    .includes(query) ||
                String(product.sku ?? '')
                    .toLowerCase()
                    .includes(query)
        );
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
            options: (variant.options?.toArray?.() ?? Array.from(variant.options ?? [])).map((option) => ({
                option,
                isSelected: (this.configVariants[variant.id] ?? []).some((selected) => selected.id === option.id),
            })),
        }));
    }

    get configAddonRows() {
        const categories = this.configuring?.addon_categories?.toArray?.() ?? Array.from(this.configuring?.addon_categories ?? []);

        return categories.map((category) => {
            const excluded = new Set((get(category, 'excluded_addons') ?? []).map((id) => String(id)));
            const available = get(category, 'category.addons');
            const addons = (available?.toArray?.() ?? Array.from(available ?? [])).filter((addon) => !excluded.has(String(addon.id)));

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

        this.args.onAdd?.(product, 1, [], []);
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

        this.args.onAdd?.(product, this.configQuantity, Object.values(this.configVariants).flat(), this.configAddons);
        this.configuring = null;
    }

    @action cancelConfig() {
        this.configuring = null;
    }
}
