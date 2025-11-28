import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PromotionsPushNotificationsRoute extends Route {
    @service store;
    @service currentUser;
    @service storefront;
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    beforeModel() {
        if (this.abilities.cannot('storefront send push notifications')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }
}
