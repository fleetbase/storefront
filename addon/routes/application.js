import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { getOwner } from '@ember/owner';
import { hash } from 'rsvp';

/**
 * Whether a transition only reloads what is on screen: the route whose data is loading is
 * already showing and the query params (filters, search, page) are the same. A param at its
 * default counts as absent: a refresh leaves defaults out of the URL the page had them in.
 *
 * @param {Object} defaults urlKey → serialized default value, of the routes involved
 */
export function isReloadOnScreen(transition, originRoute, defaults = {}) {
    const name = originRoute?.fullRouteName;
    let showing = false;
    for (let info = transition?.from; info; info = info.parent) {
        if (info.name === name) {
            showing = true;
            break;
        }
    }
    if (!showing) {
        return false;
    }

    const normalize = (params = {}) =>
        Object.entries(params ?? {})
            .filter(([key, value]) => value !== undefined && value !== null && value !== '' && String(value) !== String(defaults[key] ?? ''))
            .map(([key, value]) => `${key}=${value}`)
            .sort()
            .join('&');

    return normalize(transition.from?.queryParams) === normalize(transition.to?.queryParams);
}

export default class ApplicationRoute extends Route {
    @service fetch;
    @service store;
    @service loader;
    @service currentUser;
    @service modalsManager;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;
    @service storefront;

    /**
     * Entering the engine from outside shows the overlay loader while the engine boots.
     * Moving between pages inside the engine lets the event bubble (`return true`) so the
     * route's `loading` substate renders its skeleton in place of the page. Reloading a page
     * that is already on screen (a refresh after an action, with the same filters) keeps it,
     * and any panel open over it, on screen until the new data arrives.
     */
    @action loading(transition, originRoute) {
        if (this.isTransitionFromOutside(transition)) {
            this.loader.showOnInitialTransition(transition, 'section.next-view-section', { loadingMessage: this.intl.t('storefront.common.loading-storefront') });
            return false;
        }

        if (isReloadOnScreen(transition, originRoute, this.queryParamDefaults(transition))) {
            return false;
        }

        return true;
    }

    /**
     * Toast the detail, remember the transition that failed so the error page can retry
     * it (an aborted transition leaves the URL where it was), then let the `error`
     * substate render.
     */
    @action error(error, transition) {
        this.notifications.serverError(error);

        const errorController = getOwner(this).lookup('controller:error');
        if (errorController) {
            errorController.failedTransition = transition ?? null;
        }

        return true;
    }

    /** The default of every query param of the engine's routes the transition leaves or enters. */
    queryParamDefaults(transition) {
        const owner = getOwner(this);
        const prefix = `${this.fullRouteName}.`;
        const defaults = {};
        for (const leaf of [transition?.from, transition?.to]) {
            for (let info = leaf; info; info = info.parent) {
                if (!info.name?.startsWith(prefix)) {
                    continue;
                }
                const route = owner.lookup(`route:${info.name.slice(prefix.length)}`);
                (route?._qp?.qps ?? []).forEach((qp) => {
                    defaults[qp.urlKey] = qp.serializedDefaultValue;
                });
            }
        }

        return defaults;
    }

    isTransitionFromOutside(transition) {
        const from = transition?.from?.name;
        return typeof from !== 'string' || !from.startsWith('console.storefront');
    }

    @action willTransition() {
        this.modalsManager.done();
    }

    beforeModel() {
        if (this.abilities.cannot('storefront see extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }

        this.disableSandbox();
        return this.fetch.get('actions/store-count', {}, { namespace: 'storefront/int/v1' }).then(({ storeCount }) => {
            // if no store count prompt to create a store
            if (!storeCount) {
                return this.storefront.createFirstStore();
            }
        });
    }

    model() {
        return hash({
            stores: this.store.query('store', { limit: 300, sort: '-updated_at', with: ['networks'] }),
            networks: this.abilities.cannot('storefront list network') ? [] : this.store.query('network', { limit: 300, sort: '-updated_at' }),
        });
    }

    afterModel({ stores }) {
        this.storefront.synchronizeActiveStore(stores);
        this.storefront.listenForIncomingOrders();
        this.storefront.loadPendingInvitations();
        this.storefront.loadAttention();
    }

    disableSandbox() {
        this.currentUser.setOption('sandbox', false);
        // this.theme.setEnvironment();
    }
}
