import Component from '@glimmer/component';
import { action, get } from '@ember/object';
import { inject as service } from '@ember/service';

const FALLBACK_IMAGE_URL = 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png';
const PUBLISHED_STATUSES = ['published', 'available', 'active'];

export default class StorefrontProductCardComponent extends Component {
    @service modalsManager;

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
