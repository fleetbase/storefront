import Route from '@ember/routing/route';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * The query params a transition reports as changed that really did change.
 *
 * Router-service transitions (and engine `transitionToRoute`) re-send every query param at
 * its default value, so opening a child panel reports `page=1`, `sort=-created_at` and so on
 * as "changed" against a URL that omitted them. A value counts as changed only when it
 * differs from what the active state had, a missing key meaning the param's default.
 *
 * @param {Object} changed   urlKey → serialized value, as Ember passes to queryParamsDidChange
 * @param {Object} previous  the active state's query params (urlKey → serialized value)
 * @param {Object} defaults  urlKey → serialized default value
 * @returns {Array<String>}
 */
export function realQueryParamChanges(changed = {}, previous = {}, defaults = {}) {
    const normalize = (value) => (value === undefined || value === null ? '' : String(value));

    // Only the list's own params: a child panel's (`booking`, `for_customer`) is not a list change.
    return Object.keys(changed).filter((key) => {
        if (!Object.prototype.hasOwnProperty.call(defaults, key)) {
            return false;
        }
        const before = Object.prototype.hasOwnProperty.call(previous, key) ? previous[key] : defaults[key];
        return normalize(changed[key]) !== normalize(before);
    });
}

/**
 * Base for list routes whose query params refresh the model. Opening or closing a child
 * route (a details or create panel) no longer re-runs the list's model and its loading
 * skeleton; a real filter, sort or page change still does.
 */
export default class StorefrontListRoute extends Route {
    @service hostRouter;

    @action queryParamsDidChange(changed = {}, totalPresent, removed = {}) {
        // The route the user is on until this transition finishes; its query params are the URL's.
        const previous = this.hostRouter?.currentRoute?.queryParams ?? {};
        const ownQps = this._qp?.qps ?? [];
        const defaults = Object.fromEntries(ownQps.map((qp) => [qp.urlKey, qp.serializedDefaultValue]));
        // A child route dropping its own param (a panel's `for_customer`) is not a list change.
        const ownRemovals = Object.keys(removed ?? {}).filter((key) => Object.prototype.hasOwnProperty.call(defaults, key));

        const real = realQueryParamChanges(changed, previous, defaults);
        if (ownRemovals.length === 0 && real.length === 0) {
            // Nothing the list depends on changed; let the event keep bubbling as Ember's would.
            return true;
        }

        // Ember refreshes for any of the route's params merely present in `changed`, and the router
        // re-sends them all: hand it only the ones that changed.
        const onlyReal = Object.fromEntries(real.map((key) => [key, changed[key]]));
        const onlyOwnRemovals = Object.fromEntries(ownRemovals.map((key) => [key, removed[key]]));

        return Route.prototype.actions.queryParamsDidChange.call(this, onlyReal, totalPresent, onlyOwnRemovals);
    }
}
