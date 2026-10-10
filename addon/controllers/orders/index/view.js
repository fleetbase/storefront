import { action } from '@ember/object';
import BaseController from '../../base-controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

const TAB_ICONS = {
    items: 'box-open',
    route: 'route',
    payment: 'credit-card',
    activity: 'wave-square',
    customer: 'user',
    data: 'database',
};

export default class OrdersIndexViewController extends BaseController {
    @service storefrontOrderActions;
    @service intl;
    @service('universe/menu-service') menuService;

    /**
     * Items, Route, Payment, Activity, Customer and Data; tabs registered by other
     * extensions on `storefront:component:order:details` are appended.
     */
    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('storefront:component:order:details');
        const ownTabs = Object.keys(TAB_ICONS).map((id) => ({
            id,
            label: this.intl.t(`storefront.order.tabs.${id}`),
            icon: TAB_ICONS[id],
            component: `storefront/order/details/tabs/${id}`,
        }));
        const extraTabs = (isArray(registeredTabs) ? registeredTabs : []).map((item) => ({
            id: `registered:${item.slug ?? item.id}`,
            label: item.title ?? item.label,
            icon: item.icon,
            registeredSlug: item.slug ?? item.id,
        }));

        return [...ownTabs, ...extraTabs];
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
