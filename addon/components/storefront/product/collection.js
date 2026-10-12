import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class StorefrontProductCollectionComponent extends Component {
    get products() {
        return this.args.products ?? [];
    }

    get hasProducts() {
        return this.products.length > 0;
    }

    get isListView() {
        return this.args.viewMode === 'list';
    }

    /** `selectedIds` is a Set from the products controller; an array works too. */
    isSelected = (product) => {
        const selected = this.args.selectedIds;
        return Boolean(selected?.has?.(product?.id) ?? selected?.includes?.(product?.id));
    };

    get allSelected() {
        return this.hasProducts && this.products.every((product) => this.isSelected(product));
    }

    /** Header checkbox: selects every product on the page, or clears them when all are selected. */
    @action toggleAll() {
        const selectAll = !this.allSelected;

        for (const product of this.products) {
            if (this.isSelected(product) !== selectAll) {
                this.args.onToggleSelect?.(product);
            }
        }
    }
}
