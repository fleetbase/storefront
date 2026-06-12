import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { dasherize } from '@ember/string';
import CustomerPanelDetailsComponent from './customer-panel/details';
import CustomerPanelOrdersComponent from './customer-panel/orders';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import applyContextComponentArguments from '@fleetbase/ember-core/utils/apply-context-component-arguments';

export default class CustomerPanelComponent extends Component {
    /**
     * Service for fetching data.
     *
     * @type {Service}
     */
    @service fetch;

    /**
     * Service for managing modals.
     *
     * @type {Service}
     */
    @service modalsManager;

    /**
     * Universe service for managing global data and settings.
     *
     * @type {Service}
     */
    @service universe;

    /**
     * Ember data store service.
     *
     * @type {Service}
     */
    @service store;

    /**
     * Service for managing routing within the host app.
     *
     * @type {Service}
     */
    @service hostRouter;

    /**
     * Service for managing the context panel.
     *
     * @type {Service}
     */
    @service contextPanel;

    /**
     * The current active tab.
     *
     * @type {Object}
     * @tracked
     */
    @tracked tab;

    /**
     * The customer being displayed or edited.
     *
     * @type {customerModel}
     * @tracked
     */
    @tracked customer;

    /**
     * Overlay context.
     * @type {any}
     */
    @tracked context;

    /**
     * Initializes the customer panel component.
     */
    constructor() {
        super(...arguments);
        this.customer = this.args.customer;

        this.tab = this.getTabUsingSlug(this.args.tab);
        applyContextComponentArguments(this);
    }

    /**
    /**
     * Returns the array of tabs available for the panel.
     *
     * @type {Array}
     */
    get tabs() {
        const registeredTabs = this.universe.getMenuItemsFromRegistry('component:customer-panel');

        const defaultTabs = [
            this.universe._createMenuItem('Details', null, { icon: 'circle-info', component: CustomerPanelDetailsComponent }),
            this.universe._createMenuItem('Orders', null, { icon: 'circle-info', component: CustomerPanelOrdersComponent }),
        ];

        if (isArray(registeredTabs)) {
            return [...defaultTabs, ...registeredTabs].map((tab) => this.normalizeTab(tab));
        }

        return defaultTabs.map((tab) => this.normalizeTab(tab));
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                helpText: 'Edit customer',
                onClick: this.onEdit,
                permission: 'storefront update customer',
            },
        ];
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
     * Handles changing the active tab.
     *
     * @method
     * @param {String} tab - The new tab to switch to.
     * @action
     */
    @action onTabChanged(tab) {
        this.tab = tab;
        contextComponentCallback(this, 'onTabChanged', tab?.slug ?? tab?.id);
    }

    /**
     * Handles edit action for the customer.
     *
     * @method
     * @action
     */
    @action onEdit() {
        const isActionOverrided = contextComponentCallback(this, 'onEdit', this.customer);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.customer, 'editing', {
                onAfterSave: () => {
                    this.contextPanel.clear();
                },
            });
        }
    }

    /**
     * Handles the cancel action.
     *
     * @method
     * @action
     * @returns {Boolean} Indicates whether the cancel action was overridden.
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.customer);
    }

    /**
     * Finds and returns a tab based on its slug.
     *
     * @param {String} tabSlug - The slug of the tab.
     * @returns {Object|null} The found tab or null.
     */
    getTabUsingSlug(tabSlug) {
        if (tabSlug) {
            return this.tabs.find(({ slug, id }) => slug === tabSlug || id === tabSlug);
        }

        return this.tabs[0];
    }

    normalizeTab(tab) {
        const id = tab.id ?? tab.slug ?? dasherize(tab.title ?? tab.text ?? tab.label ?? 'tab');

        return {
            ...tab,
            id,
            slug: tab.slug ?? id,
            label: tab.label ?? tab.title ?? tab.text,
        };
    }
}
