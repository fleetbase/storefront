import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { dasherize } from '@ember/string';
import CustomerPanelOverviewComponent from './customer-panel/overview';
import CustomerPanelOrdersComponent from './customer-panel/orders';
import CustomerPanelPlacesComponent from './customer-panel/places';
import CustomerPanelActivityComponent from './customer-panel/activity';
import { task } from 'ember-concurrency';
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
    @service intl;
    @service notifications;

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
        this.loadInsights.perform();

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
            this.universe._createMenuItem(this.intl.t('storefront.customers.panel.tabs.overview'), null, { icon: 'circle-info', component: CustomerPanelOverviewComponent, componentParams: { insights: this.insights, onChange: this.reload } }),
            this.universe._createMenuItem(this.intl.t('storefront.customers.panel.tabs.orders'), null, { icon: 'file-invoice-dollar', component: CustomerPanelOrdersComponent }),
            this.universe._createMenuItem(this.intl.t('storefront.customers.panel.tabs.places'), null, { icon: 'map-marker-alt', component: CustomerPanelPlacesComponent, componentParams: { onChange: this.reload } }),
            this.universe._createMenuItem(this.intl.t('storefront.customers.panel.tabs.activity'), null, { icon: 'wave-square', component: CustomerPanelActivityComponent, componentParams: { insights: this.insights } }),
        ];

        if (isArray(registeredTabs)) {
            return [...defaultTabs, ...registeredTabs].map((tab) => this.normalizeTab(tab));
        }

        return defaultTabs.map((tab) => this.normalizeTab(tab));
    }

    get actionButtons() {
        return [
            {
                icon: 'plus',
                text: this.intl.t('storefront.customers.panel.new-order'),
                size: 'sm',
                type: 'primary',
                onClick: this.newOrder,
                permission: 'storefront create order',
            },
            {
                icon: 'pencil',
                text: this.intl.t('common.edit'),
                size: 'sm',
                onClick: this.onEdit,
                permission: 'storefront update customer',
            },
            {
                items: [
                    { text: this.intl.t('storefront.customers.panel.copy-id'), icon: 'copy', fn: this.copyId },
                    { text: this.intl.t('storefront.customers.panel.copy-email'), icon: 'at', fn: this.copyEmail, disabled: !this.customer?.email },
                ],
            },
        ];
    }

    @task *loadInsights() {
        const customer = this.customer;

        if (!customer?.id) {
            return;
        }

        const scope = this.storefront.isNetworkContext ? { network: this.storefront.getActiveNetwork('public_id') } : { storefront: this.storefront.getActiveStore('public_id') };

        try {
            this.insights = yield this.fetch.get(`customers/${customer.id}/insights`, scope, { namespace: 'storefront/int/v1' });
        } catch {
            this.insights = null;
        }
    }

    @action reload() {
        return this.loadInsights.perform();
    }

    @action newOrder() {
        try {
            return this.hostRouter.transitionTo('console.storefront.orders.index.new', { queryParams: { customer: this.customer?.public_id } });
        } catch {
            return this.hostRouter.transitionTo('console.storefront.orders.index.new');
        }
    }

    @action async copyId() {
        return this.copy(this.customer?.public_id);
    }

    @action async copyEmail() {
        return this.copy(this.customer?.email);
    }

    async copy(value) {
        if (!value) {
            return;
        }

        try {
            await navigator.clipboard.writeText(value);
            this.notifications.success(this.intl.t('storefront.customers.panel.copied', { value }));
        } catch {
            this.notifications.info(value);
        }
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
