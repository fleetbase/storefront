import Component from '@glimmer/component';

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

    get title() {
        return this.args.category ? `${this.args.category.name} Products` : 'All Products';
    }
}
