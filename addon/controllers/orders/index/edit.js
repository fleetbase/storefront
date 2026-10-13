import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OrdersIndexEditController extends Controller {
    @service hostRouter;
    queryParams = ['focus'];
    focus = null;

    @action backToOrder() {
        return this.hostRouter.transitionTo('console.storefront.orders.index.view', this.model.public_id);
    }

    @action saved() {
        this.hostRouter.refresh();
        return this.backToOrder();
    }
}
