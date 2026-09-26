import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class PromotionsController extends Controller {
    @service intl;

    get tabs() {
        return [
            {
                route: 'promotions.index',
                label: this.intl.t('storefront.promotions.list.tab-title'),
                icon: 'tags',
            },
            {
                route: 'promotions.campaigns',
                label: this.intl.t('storefront.promotions.campaigns.tab-title'),
                icon: 'bullhorn',
            },
            {
                route: 'promotions.segments',
                label: this.intl.t('storefront.promotions.segments.tab-title'),
                icon: 'users',
            },
            {
                route: 'promotions.push-notifications',
                label: this.intl.t('storefront.promotions.push-notifications.tab-title'),
                icon: 'bell',
            },
        ];
    }
}
