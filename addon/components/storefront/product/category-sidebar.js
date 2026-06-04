import Component from '@glimmer/component';

export default class StorefrontProductCategorySidebarComponent extends Component {
    get categories() {
        return this.args.categories ?? [];
    }
}
