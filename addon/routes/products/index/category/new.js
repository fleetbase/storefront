import Route from '@ember/routing/route';

export default class ProductsIndexCategoryNewRoute extends Route {
    didTransition() {
        if (this.controller) {
            this.controller.reset();
        }
    }
}
