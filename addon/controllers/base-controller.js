import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { getOwner } from '@ember/application';
import lookupResourceView, { mergeHeaderButtons } from '@fleetbase/ember-ui/utils/resource-view';

export default class BaseController extends Controller {
    @service hostRouter;

    @action transitionToRoute(route, ...args) {
        return this.hostRouter.transitionTo(`console.storefront.${route}`, ...args);
    }

    /**
     * The query params an index controller declares, plus the filter params of
     * columns extensions registered under `storefront:<resource>:table`.
     *
     * @param {String} resource e.g. 'order'
     * @param {Array} baseQueryParams
     * @returns {Array}
     */
    registeredQueryParams(resource, baseQueryParams = []) {
        return lookupResourceView(getOwner(this))?.queryParamsFor('storefront', resource, baseQueryParams) ?? baseQueryParams;
    }

    /**
     * `columns` with the columns and row actions extensions registered under
     * `storefront:<resource>:table` merged in, for views that render their own `<Table>`.
     *
     * @param {String} resource e.g. 'promotion'
     * @param {Array} columns
     * @returns {Array}
     */
    mergeRegisteredColumns(resource, columns = []) {
        const resourceView = lookupResourceView(getOwner(this));
        if (!resourceView) {
            return columns;
        }

        const registry = `storefront:${resource}:table`;
        const context = { controller: this };
        return resourceView.mergeRowActions(registry, resourceView.mergeSlot(registry, 'columns', columns, context), context);
    }

    /**
     * The toolbar buttons extensions registered under `storefront:<resource>:table:actions`,
     * for views that lay out their own header.
     *
     * @param {String} resource
     * @returns {Array}
     */
    registeredTableActions(resource) {
        return mergeHeaderButtons(lookupResourceView(getOwner(this)), `storefront:${resource}:table`, [], { controller: this });
    }
}
