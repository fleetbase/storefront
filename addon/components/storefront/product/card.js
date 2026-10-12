import Component from '@glimmer/component';
import { action, get } from '@ember/object';
import { inject as service } from '@ember/service';

const FALLBACK_IMAGE_URL = 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png';
const PUBLISHED_STATUSES = ['published', 'available', 'active'];

export default class StorefrontProductCardComponent extends Component {
    @service modalsManager;
    @service intl;

    get imageUrl() {
        return this.args.product?.primary_image_url ?? FALLBACK_IMAGE_URL;
    }

    get categoryName() {
        const category = this.args.product?.category;
        return category ? get(category, 'name') : null;
    }

    get variantCount() {
        return this.args.product?.variants?.length ?? 0;
    }

    get addonGroupCount() {
        return this.args.product?.addon_categories?.length ?? 0;
    }

    /** Chips only say something when there is something: a card with no variants or add-ons shows none. */
    get hasChips() {
        const { product } = this.args;
        return Boolean(this.variantCount || this.addonGroupCount || this.catalogsCount || product?.is_service || product?.is_bookable);
    }

    get catalogsCount() {
        const count = parseInt(this.args.product?.catalogs_count, 10);
        return Number.isFinite(count) ? count : 0;
    }

    /** "1 variant · 2 add-ons · in 3 catalogs" for the list view's options column. */
    get optionsSummary() {
        const { product } = this.args;
        const parts = [];

        if (this.variantCount) parts.push(this.intl.t('storefront.products.card.variants', { count: this.variantCount }));
        if (this.addonGroupCount) parts.push(this.intl.t('storefront.products.card.addons', { count: this.addonGroupCount }));
        if (this.catalogsCount) parts.push(this.intl.t('storefront.products.card.in-catalogs', { count: this.catalogsCount }));
        if (product?.is_service) parts.push(this.intl.t('storefront.products.card.service'));
        if (product?.is_bookable) parts.push(this.intl.t('storefront.products.card.bookable'));

        return parts.join(' · ');
    }

    get hasSalePrice() {
        const { product } = this.args;
        return Boolean(product?.is_on_sale && product?.sale_price);
    }

    get displayStatus() {
        const { product } = this.args;
        const status = product?.status;

        if (status === 'draft') {
            return 'draft';
        }

        if (PUBLISHED_STATUSES.includes(status)) {
            return 'published';
        }

        return product?.is_available === false ? 'draft' : 'published';
    }

    get isDraft() {
        return this.displayStatus === 'draft';
    }

    /**
     * What a draft still needs before it can publish cleanly.
     */
    get missing() {
        const { product } = this.args;
        const missing = [];

        if (!product) {
            return missing;
        }

        if (!product.category_uuid && !product.category?.id) {
            missing.push('category');
        }

        if (!product.primary_image_url) {
            missing.push('image');
        }

        if (product.price === null || product.price === undefined || product.price === '' || !(parseFloat(product.price) >= 0)) {
            missing.push('price');
        }

        return missing;
    }

    get isSelected() {
        return Boolean(this.args.isSelected);
    }

    get isOutOfStock() {
        return this.args.product?.is_available === false && !this.isDraft;
    }

    @action toggleSelect() {
        this.args.onToggleSelect?.(this.args.product);
    }

    @action previewImage() {
        this.modalsManager.show('modals/product-image-preview', {
            title: this.args.product?.name ?? 'Product image',
            modalClass: 'storefront-product-image-preview-modal',
            modalBodyClass: 'storefront-product-image-preview-modal__body',
            hideFooterActions: true,
            modalFooterClass: 'hidden-i',
            imageUrl: this.imageUrl,
            product: this.args.product,
        });
    }
}
