import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * One place for offers, the campaigns that announce them, the segments they target,
 * the pushes that go out and the redemptions they produced.
 */
export default class PromotionsController extends Controller {
    @service intl;
    @service hostRouter;
    @service storefront;
    @tracked hub = null;

    get currency() {
        return this.storefront.getActiveStore('currency') ?? 'USD';
    }

    get tabs() {
        return [
            { id: 'promotions', route: 'promotions.index', label: this.intl.t('storefront.promotions.list.tab-title'), icon: 'tags', count: this.hub?.promotions?.total },
            { id: 'campaigns', route: 'promotions.campaigns', label: this.intl.t('storefront.promotions.campaigns.tab-title'), icon: 'bullhorn', count: this.hub?.campaigns },
            { id: 'segments', route: 'promotions.segments', label: this.intl.t('storefront.promotions.segments.tab-title'), icon: 'users', count: this.hub?.segments },
            { id: 'push-notifications', route: 'promotions.push-notifications', label: this.intl.t('storefront.promotions.push-notifications.tab-title'), icon: 'bell' },
            { id: 'redemptions', route: 'promotions.redemptions', label: this.intl.t('storefront.promotions.redemptions.tab-title'), icon: 'receipt', count: this.hub?.redemptions?.total },
        ].map((tab) => ({ ...tab, isActive: String(this.hostRouter.currentRouteName ?? '').endsWith(tab.route) }));
    }

    @action openTab(tab) {
        return this.hostRouter.transitionTo(`console.storefront.${tab.route}`);
    }

    @action refreshHub() {
        return this.hostRouter.refresh();
    }
}
