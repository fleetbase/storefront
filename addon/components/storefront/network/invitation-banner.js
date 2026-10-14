import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * The store's open network invitations, shown on its dashboard until each is answered.
 */
export default class StorefrontNetworkInvitationBannerComponent extends Component {
    @service storefront;
    @service hostRouter;

    get invitations() {
        return this.storefront.pendingInvitations ?? [];
    }

    @action review(invitation) {
        return this.hostRouter.transitionTo('console.storefront.networks.join', invitation.uri);
    }
}
