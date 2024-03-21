import BaseController from '../../base-controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed, get } from '@ember/object';

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
