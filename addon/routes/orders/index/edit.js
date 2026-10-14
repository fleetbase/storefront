import OrdersIndexViewRoute from './view';

/**
 * Edit a placed order: the same order the panel shows, opened in the edit form. Only users who
 * may update orders get here.
 */
export default class OrdersIndexEditRoute extends OrdersIndexViewRoute {
    queryParams = { focus: { refreshModel: false } };

    beforeModel() {
        if (this.abilities.cannot('storefront update order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }
}
