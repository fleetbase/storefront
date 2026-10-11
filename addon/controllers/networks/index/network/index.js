import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { getOwner } from '@ember/application';
import { formatDistanceToNow } from 'date-fns';
import createShareableLink from '../../../../utils/create-shareable-link';

export default class NetworksIndexNetworkIndexController extends Controller {
    @service intl;
    @service store;
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

    /**
     * Member cards: the store's membership badge, and the store record when this
     * organisation administers it (so the card can open its context).
     */
    get memberRows() {
        const ownStores = this.store.peekAll('store');

        return this.members.map((member) => {
            const status = member.membership_status ?? 'active';
            let badgeStatus = member.online ? 'online' : 'offline';
            let badgeLabel = member.online ? this.intl.t('storefront.common.active') : this.intl.t('storefront.common.offline');

            if (status === 'suspended') {
                badgeStatus = 'danger';
                badgeLabel = this.intl.t('storefront.networks.index.network.stores.suspended');
            } else if (status === 'pending') {
                badgeStatus = 'warning';
                badgeLabel = this.intl.t('storefront.networks.index.network.stores.awaiting-approval');
            }

            return { member, badgeStatus, badgeLabel, ownStore: ownStores.find((store) => store.public_id === member.public_id) ?? null };
        });
    }

    @action viewMember(member) {
        return this.hostRouter.transitionTo('console.storefront.networks.index.network.stores', this.network.public_id, { queryParams: { storeQuery: member.name } });
    }

    @action openMemberStore(store) {
        const application = getOwner(this).lookup('controller:application');

        return application?.switchActiveStore?.(store);
    }

    /** The header's meta line: currency · timezone · id, as the board draws it. */
    get headerMeta() {
        return [this.network?.currency, this.network?.timezone, this.network?.public_id].filter(Boolean).join(' · ');
    }

    get linkJoins() {
        return Number(this.network?.options?.shareable_link_joins ?? 0);
    }

    @action viewOrder(order) {
        return this.hostRouter.transitionTo('console.storefront.networks.index.network.orders', this.network.public_id, { queryParams: { o_query: order.public_id } });
    }

    get recentOrders() {
        return this.overview?.recent_orders ?? [];
    }

    get openInvitations() {
        return this.invitations
            .filter((invitation) => ['pending', 'declined', 'expired'].includes(invitation.status))
            .map((invitation) => ({ ...invitation, sentAgo: this.sentAgo(invitation) }));
    }

    get pendingCount() {
        return this.invitations.filter((invitation) => invitation.status === 'pending').length;
    }

    get declinedCount() {
        return this.invitations.filter((invitation) => invitation.status === 'declined').length;
    }

    get shareableLink() {
        return createShareableLink(`storefront/networks/join/${this.network?.options?.shareable_link_token ?? this.network?.public_id}`);
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
