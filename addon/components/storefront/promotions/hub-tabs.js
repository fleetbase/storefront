import Component from '@glimmer/component';
import { getOwner } from '@ember/application';
import { action } from '@ember/object';

/**
 * The promotions hub tab strip (promotions, campaigns, segments, pushes, redemptions).
 * Every hub page renders it directly under its own section header so the header
 * stays the console's section header and the tabs read as part of the page body.
 */
export default class StorefrontPromotionsHubTabsComponent extends Component {
    get hub() {
        return getOwner(this).lookup('controller:promotions');
    }

    get tabs() {
        return this.hub?.tabs ?? [];
    }

    @action openTab(tab) {
        return this.hub?.openTab(tab);
    }
}
