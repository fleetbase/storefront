import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OrdersIndexEditController extends Controller {
    @service hostRouter;
    @service intl;
    @service storefrontOrderActions;
    queryParams = ['focus'];
    focus = null;

    /** The order's edit stage, from the overview the edit form loaded. */
    get stage() {
        const panel = this.storefrontOrderActions.panel;
        const overview = panel.overview;

        return panel.orderId === this.model?.public_id ? (overview?.edit?.stage ?? null) : null;
    }

    get stageNote() {
        return this.stage ? this.intl.t(`storefront.order.edit.stage.${this.stage}`) : null;
    }

    @action backToOrder() {
        return this.hostRouter.transitionTo('console.storefront.orders.index.view', this.model.public_id);
    }

    @action saved() {
        this.hostRouter.refresh('console.storefront.orders.index');
        return this.backToOrder();
    }
}
