import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';

/**
 * A time formatted (date-fns pattern) on a store's clock: the order's store when `order=` is
 * given, else the active store's. Store and order times read the same wherever the browser is.
 *
 * {{store-time order.created_at "d MMM, HH:mm" order=order}}
 */
export default class StoreTimeHelper extends Helper {
    @service storefrontOrderActions;

    compute([value, pattern = 'd MMM HH:mm'], { order } = {}) {
        if (!value) {
            return '';
        }

        return this.storefrontOrderActions.formatForOrder(order, value, pattern);
    }
}
