import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/** Which section of the page holds each field an issue can point at. */
const SECTION_FOR_FIELD = { name: 'product', price: 'pricing', sale_price: 'pricing', category: 'organization', media: 'media' };

function number(value) {
    const parsed = parseFloat(value);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * The product editor as one page, like the create order form: a main column of sections
 * (product, media, pricing, variants, add-ons, hours, more) and a rail with status,
 * organization and flags. Validation is inline; the save bar counts changes and jumps to
 * the first section that needs attention.
 */
export default class StorefrontProductFormComponent extends Component {
    @service intl;
    @tracked moreOpen = false;
    /** A new product shows its inline issues once a save has been tried, not on an empty form. */
    @tracked saveAttempted = false;

    get product() {
        return this.args.product;
    }

    get variantsCount() {
        return this.product?.variants?.length ?? 0;
    }

    get addonCategoriesCount() {
        return this.product?.addon_categories?.length ?? 0;
    }

    get mediaCount() {
        return (this.product?.files?.length ?? 0) + (Array.isArray(this.product?.youtube_urls) ? this.product.youtube_urls.length : 0);
    }

    get translationsCount() {
        const translations = this.product?.translations;

        return translations && typeof translations === 'object' ? Object.keys(translations).length : 0;
    }

    get metadataCount() {
        const meta = this.product?.meta;

        return meta && typeof meta === 'object' ? Object.keys(meta).length : 0;
    }

    /**
     * What blocks a clean publish, each tied to the tab that holds the field.
     */
    get issues() {
        const product = this.product;
        const issues = [];

        if (!product) {
            return issues;
        }

        if (!String(product.name ?? '').trim()) {
            issues.push({ tab: 'details', field: 'name', message: this.intl.t('storefront.products.form.issues.name') });
        }

        const price = number(product.price);
        const salePrice = number(product.sale_price);

        if (price === null || price < 0) {
            issues.push({ tab: 'details', field: 'price', message: this.intl.t('storefront.products.form.issues.price') });
        }

        if (product.is_on_sale && salePrice !== null && price !== null && salePrice >= price) {
            issues.push({ tab: 'details', field: 'sale_price', message: this.intl.t('storefront.products.form.issues.sale-price') });
        }

        if (product.is_on_sale && salePrice === null) {
            issues.push({ tab: 'details', field: 'sale_price', message: this.intl.t('storefront.products.form.issues.sale-price-missing') });
        }

        if (!product.category_uuid && !product.category?.id && product.status === 'published') {
            issues.push({ tab: 'details', field: 'category', message: this.intl.t('storefront.products.form.issues.category') });
        }

        if (product.status === 'published' && this.mediaCount === 0 && !product.primary_image_url) {
            issues.push({ tab: 'media', field: 'media', message: this.intl.t('storefront.products.form.issues.media') });
        }

        return issues;
    }

    /** Issue messages for one section, shown at its top. */
    issuesFor = (section) =>
        (this.product?.isNew && !this.saveAttempted ? [] : this.issues).filter((issue) => (SECTION_FOR_FIELD[issue.field] ?? 'product') === section).map((issue) => issue.message);

    get dirtyCount() {
        const product = this.product;

        if (!product) {
            return 0;
        }

        if (product.isNew) {
            return 1;
        }

        const changed = Object.keys(product.changedAttributes?.() ?? {}).length;
        const dirtyVariants = (product.variants?.toArray?.() ?? []).filter((variant) => variant.hasDirtyAttributes || variant.isNew).length;
        const dirtyAddons = (product.addon_categories?.toArray?.() ?? []).filter((category) => category.hasDirtyAttributes || category.isNew).length;
        const queued = this.args.uploadQueue?.length ?? 0;

        return changed + dirtyVariants + dirtyAddons + queued;
    }

    get isDirty() {
        return this.dirtyCount > 0;
    }

    @action save(...args) {
        this.saveAttempted = true;
        return this.args.onSave?.(...args);
    }

    @action toggleMore() {
        this.moreOpen = !this.moreOpen;
    }

    @action jumpToIssue(issue) {
        const section = SECTION_FOR_FIELD[issue?.field] ?? 'product';
        document.getElementById(`product-section-${section}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
