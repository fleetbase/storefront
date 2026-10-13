import { action } from '@ember/object';
import BaseController from '../../base-controller';
import { inject as service } from '@ember/service';

export default class OrdersIndexViewController extends BaseController {
    @service storefrontOrderActions;

    get actionButtons() {
        return this.storefrontOrderActions.actionButtonsFor(this.model, this.refreshOrder);
    }

    @action refreshOrder() {
        return this.hostRouter.refresh();
    }

    /**
     * Uses router service to transition back to `orders.index`
     *
     * @void
     */
    @action transitionBack() {
        return this.transitionToRoute('orders.index');
    }
}
