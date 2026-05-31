import { action } from '@ember/object';
import BaseController from '../../base-controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class OrdersIndexViewController extends BaseController {
    @service storefrontOrderActions;
    @service('universe/menu-service') menuService;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('storefront:component:order:details');

        return [
            {
                route: 'orders.index.view.index',
                label: 'Overview',
                icon: 'folder-open',
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
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
