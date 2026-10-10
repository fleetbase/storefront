import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { formatDistanceToNow } from 'date-fns';
import createShareableLink from '../../../../utils/create-shareable-link';

export default class NetworksIndexNetworkIndexController extends Controller {
    @service intl;
    @service notifications;
    @service hostRouter;
    @service modalsManager;
    @tracked network;
    @tracked overview;
    @tracked invitations = [];
    @tracked isInvitePanelOpen = false;
    @tracked invitePanelTab = 'email';

    get currency() {
        return this.overview?.currency ?? this.network?.currency;
    }

    get members() {
        return this.overview?.members ?? [];
    }

    get recentOrders() {
        return this.overview?.recent_orders ?? [];
    }

    get openInvitations() {
        return this.invitations.filter((invitation) => ['pending', 'declined', 'expired'].includes(invitation.status)).map((invitation) => ({ ...invitation, sentAgo: this.sentAgo(invitation) }));
    }

    get pendingCount() {
        return this.invitations.filter((invitation) => invitation.status === 'pending').length;
    }

    get declinedCount() {
        return this.invitations.filter((invitation) => invitation.status === 'declined').length;
    }

    get shareableLink() {
        return createShareableLink(`join/network/${this.network?.public_id}`);
    }

    get shareableLinkEnabled() {
        return Boolean(this.network?.options?.shareable_link_enabled);
    }

    sentAgo(invitation) {
        const sentAt = invitation.resent_at ?? invitation.created_at;

        return sentAt ? formatDistanceToNow(new Date(sentAt), { addSuffix: true }) : null;
    }

    @action openInvitePanel(tab = 'email') {
        this.invitePanelTab = tab;
        this.isInvitePanelOpen = true;
    }

    @action closeInvitePanel() {
        this.isInvitePanelOpen = false;
    }

    @action async refresh() {
        try {
            this.invitations = await this.network.loadInvitations();
            this.overview = await this.network.loadOverview();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action manageStores() {
        return this.hostRouter.transitionTo('console.storefront.networks.index.network.stores', this.network.public_id);
    }

    @action openSettings() {
        return this.hostRouter.transitionTo('console.storefront.networks.index.network.settings', this.network.public_id);
    }

    @action viewOrders() {
        return this.hostRouter.transitionTo('console.storefront.networks.index.network.orders', this.network.public_id);
    }

    @action async resendInvitation(invitation) {
        try {
            await this.network.resendInvitation(invitation);
            this.notifications.success(this.intl.t('storefront.networks.invitations.resent', { email: invitation.email }));
            await this.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action revokeInvitation(invitation) {
        return this.modalsManager.confirm({
            title: this.intl.t('storefront.networks.invitations.revoke-title', { email: invitation.email }),
            body: this.intl.t('storefront.networks.invitations.revoke-body'),
            acceptButtonText: this.intl.t('storefront.networks.invitations.revoke'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.network.revokeInvitation(invitation);
                    await this.refresh();
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
        });
    }
}
