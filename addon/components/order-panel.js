import Component from '@glimmer/component';
import applyContextComponentArguments from '@fleetbase/ember-core/utils/apply-context-component-arguments';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderPanelComponent extends Component {
    @service storefront;
    @service orderActions;
    @tracked context = null;
    @tracked store = null;

    constructor() {
        super(...arguments);
        applyContextComponentArguments(this);
        this.loadActiveStore.perform();
    }

    /**
     * Sets the overlay context.
     *
     * @action
     * @param {OverlayContextObject} overlayContext
     */
    @action setOverlayContext(overlayContext) {
        this.context = overlayContext;
        contextComponentCallback(this, 'onLoad', ...arguments);
    }

    /**
     * Handles the cancel action.
     *
     * @method
     * @action
     * @returns {Boolean} Indicates whether the cancel action was overridden.
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.order);
    }

    @action acceptOrder(order) {
        this.orderActions.acceptOrder(order);
    }

    @action markAsReady(order) {
        this.orderActions.markAsReady(order);
    }

    @action markAsCompleted(order) {
        this.orderActions.markAsCompleted(order);
    }

    @action assignDriver(order) {
        this.orderActions.assignDriver(order);
    }

    @action cancelOrder(order) {
        this.orderActions.cancelOrder(order);
    }

    @task *loadActiveStore() {
        const storefrontId = this.order.meta.storefront_id;
        if (!storefrontId) {
            return null;
        }

        const currentStore = this.storefront.activeStore;
        if (storefrontId === currentStore.public_id) {
            this.store = currentStore;
            return currentStore;
        }

        try {
            const store = yield this.store.findRecord('store', storefrontId);
            this.store = store;
            return store;
        } catch (err) {
            debug(`Unable to load store for ${this.order.public_id}:`, err);
        }
    }
}
