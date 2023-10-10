import Route from '@ember/routing/route';

export default class ProductsIndexCategoryNewRoute extends Route {
    didTransition() {
        this.controller?.reset();
    }
}
