import { action } from '@ember/object';
import BaseController from '../../base-controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class OrdersIndexViewController extends BaseController {
    @service storefrontOrderActions;
    @service intl;
    @service('universe/menu-service') menuService;

    /**
     * The order's own view, then tabs other extensions register on `storefront:component:order:details`.
     */
    get tabs() {
        const registered = this.menuService.getMenuItems('storefront:component:order:details');

        return [
            { id: 'details', label: this.intl.t('storefront.order.panel.details-tab'), icon: 'receipt' },
            ...(isArray(registered) ? registered : []).map((item) => ({
                id: `registered:${item.slug ?? item.id}`,
                label: item.title ?? item.label,
                icon: item.icon,
                registeredSlug: item.slug ?? item.id,
            })),
        ];
    }

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
