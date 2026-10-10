import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatDistanceToNow } from 'date-fns';

export default class NetworksJoinController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service hostRouter;
    @service storefront;
    @tracked stores = [];
    @tracked selectedStore = null;
    @tracked done = false;
    @tracked result = null;

    get invitation() {
        return this.model?.invitation;
    }

    get network() {
        return this.model?.network;
    }

    get isOpen() {
        return this.invitation?.status === 'pending';
    }

    get expiresIn() {
        return this.invitation?.expires_at ? formatDistanceToNow(new Date(this.invitation.expires_at), { addSuffix: true }) : null;
    }

    get canAccept() {
        return this.isOpen && Boolean(this.selectedStore) && !this.accept.isRunning;
    }

    @action selectStore(store) {
        this.selectedStore = store;
    }

    @task *accept() {
        try {
            this.result = yield this.fetch.post(`networks/join/${this.model.uri}/accept`, { store: this.selectedStore.id }, { namespace: 'storefront/int/v1' });
            this.done = true;
            this.notifications.success(this.intl.t('storefront.networks.join.accepted', { network: this.network.name, store: this.selectedStore.name }));
            this.storefront.loadPendingInvitations?.();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *decline() {
        try {
            yield this.fetch.post(`networks/join/${this.model.uri}/decline`, {}, { namespace: 'storefront/int/v1' });
            this.done = true;
            this.result = { declined: true };
            this.notifications.info(this.intl.t('storefront.networks.join.declined'));
            this.storefront.loadPendingInvitations?.();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action goHome() {
        return this.hostRouter.transitionTo('console.storefront.home');
    }

    @action goToNetworks() {
        return this.hostRouter.transitionTo('console.storefront.networks.index');
    }
}
