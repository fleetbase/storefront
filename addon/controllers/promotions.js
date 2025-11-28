import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class PromotionsController extends Controller {
    @service intl;

    get tabs() {
        return [
            {
                route: 'promotions.push-notifications',
                label: this.intl.t('storefront.promotions.push-notifications.tab-title'),
                icon: 'bell',
            },
        ];
    }
}
