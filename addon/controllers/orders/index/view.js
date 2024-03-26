import { action } from '@ember/object';
import BaseController from '../../base-controller';

export default class OrdersIndexViewController extends BaseController {
    /**
     * Uses router service to transition back to `orders.index`
     *
     * @void
     */
    @action transitionBack() {
        return this.transitionToRoute('orders.index');
    }
}
