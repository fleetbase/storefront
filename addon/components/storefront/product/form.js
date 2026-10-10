import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

const TABS = ['details', 'variants', 'availability', 'media'];

function number(value) {
    const parsed = parseFloat(value);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * The product editor: ten stacked panels become four tabs. Validation is inline and
 * the tab that holds an invalid field carries a badge; the save bar counts changes.
 */
export default class StorefrontProductFormComponent extends Component {
    @service intl;
    @tracked activeTab = this.args.activeTab ?? 'details';
    @tracked moreOpen = false;

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

    get issuesByTab() {
        return this.issues.reduce((map, issue) => {
            map[issue.tab] = (map[issue.tab] ?? 0) + 1;
            return map;
        }, {});
    }

    get detailIssueMessages() {
        return this.issues.filter((issue) => issue.tab === 'details').map((issue) => issue.message);
    }

    get tabs() {
        const counts = {
            details: null,
            variants: this.variantsCount + this.addonCategoriesCount || null,
            availability: this.product?.hours?.length || null,
            media: this.mediaCount || null,
        };

        return TABS.map((id) => ({
            id,
            label: this.intl.t(`storefront.products.form.tabs.${id}`),
            count: counts[id],
            issues: this.issuesByTab[id] ?? 0,
            isActive: id === this.activeTab,
        }));
    }

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

    @action selectTab(tab) {
        this.activeTab = tab.id ?? tab;
    }

    @action toggleMore() {
        this.moreOpen = !this.moreOpen;
    }

    @action jumpToIssue(issue) {
        this.activeTab = issue.tab;
    }
}
