import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { inject as controller } from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';

export default class NetworksIndexController extends BaseController {
    /**
     * Inject the `NetworksIndexNetworkStoresController` controller
     *
     * @memberof NetworksIndexController
     */
    @controller('networks.index.network.stores') networkStoresController;

    /**
     * Inject the `notifications` service
     *
     * @var {Service}
     */
    @service notifications;

    /**
     * Inject the `modals-manager` service
     *
     * @var {Service}
     */
    @service modalsManager;

    /**
     * Inject the `crud` service
     *
     * @var {Service}
     */
    @service crud;

    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service currentUser;

    /**
     * Inject the `store` service
     *
     * @var {Service}
     */
    @service store;

    /**
     * Inject the `fetch` service
     *
     * @var {Service}
     */
    @service fetch;

    /**
     * Inject the `filters` service
     *
     * @var {Service}
     */
    @service filters;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * Queryable parameters for this controller's model
     *
     * @var {Array}
     */
    queryParams = ['page', 'limit', 'sort', 'query'];

    /**
     * The current page of data being viewed.
     *
     * @var {Integer}
     */
    @tracked page = 1;

    /**
     * The search query.
     *
     * @var {String}
     */
    @tracked query;

    /**
     * The maximum number of items to show per page.
     *
     * @var {Integer}
     */
    @tracked limit;

    /**
     * The param to sort the data on, the param with prepended `-` is descending
     *
     * @var {String}
     */
    @tracked sort = '-created_at';

    /**
     * The search task.
     *
     * @void
     */
    @task({ restartable: true }) *search({ target: { value } }) {
        // if no query don't search
        if (isBlank(value)) {
            this.query = null;
            return;
        }

        // timeout for typing
        yield timeout(250);

        // reset page for results
        if (this.page > 1) {
            this.page = 1;
        }

        // update the query param
        this.query = value;
    }

    /**
     * Send invites to a network.
     *
     * @method sendInvites
     * @param {NetworkModel} network - The network object to which invites are sent.
     * @public
     */
    @action sendInvites(network) {
        this.networkStoresController.invite(network);
    }

    /**
     * Manage a specific network, transitioning to the appropriate route.
     *
     * @method manageNetwork
     * @param {NetworkModel} network - The network object to manage.
     * @public
     */
    @action manageNetwork(network) {
        this.transitionToRoute('networks.index.network', network);
    }

    /**
     * Create a new network, with optional currency properties.
     *
     * @method createNetwork
     * @public
     */
    @action createNetwork() {
        const network = this.store.createRecord('network');
        const currency = this.currentUser.getWhoisProperty('currency.code');

        if (currency) {
            network.setProperties({ currency });
        }

        this.modalsManager.show('modals/create-network', {
            title: this.intl.t('storefront.controllers.networks.index.title'),
            network,
            confirm: (modal) => {
                modal.startLoading();

                return network.save().then(() => {
                    this.notifications.success(this.intl.t('storefront.controllers.networks.index.success-message'));
                    return this.hostRouter.refresh();
                });
            },
            decline: () => {
                return network.destroyRecord();
            },
        });
    }

    /**
     * Delete a specific network, with a confirmation prompt.
     *
     * @method deleteNetwork
     * @param {NetworkModel} network - The network object to delete.
     * @public
     */
    @action deleteNetwork(network) {
        return this.crud.delete(network, {
            title: this.intl.t('storefront.networks.index.title-network', { networkName: network.name }),
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }
}
