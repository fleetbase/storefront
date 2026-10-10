import Controller, { inject as controller } from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { alias } from '@ember/object/computed';
import { action } from '@ember/object';
import getPodMethods from '@fleetbase/ember-core/utils/get-pod-methods';

/**
 * NetworksIndexNetworkSettingsController
 *
 * This controller handles the logic for managing networks, gateways, and notification channels.
 *
 * @class NetworksIndexNetworkSettingsController
 * @extends Controller
 */
export default class NetworksIndexNetworkSettingsController extends Controller {
    /**
     * Controller for managing gateways.
     *
     * @property {Controller} gatewaysController
     */
    @controller('settings.gateways') gatewaysController;

    /**
     * Controller for managing notifications.
     *
     * @property {Controller} notificationsController
     */
    @controller('settings.notifications') notificationsController;

    /**
     * Notifications service to handle notification logic.
     *
     * @property {Service} notifications
     */
    @service notifications;

    /**
     * Fetch service to handle file uploads and other network requests.
     *
     * @property {Service} fetch
     */
    @service fetch;

    /**
     * intl service to handle file uploads and other network requests.
     *
     * @property {Service} intl
     */
    @service intl;

    /**
     * Store service to handle file uploads and other network requests.
     *
     * @property {Service} store
     */
    @service store;

    /**
     * Proof of delivery methods.
     *
     * @property {Array} podMethods
     */
    @tracked podMethods = getPodMethods();

    /**
     * Loading state, indicating whether a network request is in progress.
     *
     * @property {Boolean} isLoading
     */
    @tracked isLoading = false;

    @tracked orderConfigs = [];

    /** The settings page is one route; the rail scrolls to its sections. */
    @tracked activeSection = 'general';
    @tracked optionsRevision = 0;
    optionsSnapshot = '{}';
    alertableSnapshot = '{}';

    get railItems() {
        const item = (id, label, icon, badge = null) => ({ id, label, icon, section: `network-settings-${id}`, isActive: this.activeSection === id, badge });

        return [
            item('general', this.intl.t('storefront.common.general'), 'cog'),
            item('branding', this.intl.t('storefront.settings.sections.branding'), 'image'),
            item('rules', this.intl.t('storefront.settings.sections.checkout-rules'), 'cart-shopping'),
            item('alerts', this.intl.t('storefront.common.alerts'), 'bell'),
            item('gateways', this.intl.t('storefront.settings.sections.gateways'), 'cash-register', this.gateways?.length || null),
            item('notifications', this.intl.t('storefront.settings.sections.notifications'), 'bell-concierge', this.channels?.length || null),
            item('api', this.intl.t('storefront.settings.sections.api-keys'), 'code'),
        ];
    }

    @action openItem(item) {
        this.activeSection = item.id;
        document.getElementById(item.section)?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
    }

    get optionsChanged() {
        this.optionsRevision;

        return JSON.stringify(this.model?.options ?? {}) !== this.optionsSnapshot;
    }

    get alertableChanged() {
        this.optionsRevision;

        return JSON.stringify(this.model?.alertable ?? {}) !== this.alertableSnapshot;
    }

    get changedAttributeNames() {
        const changed = this.model?.changedAttributes?.() ?? {};

        return Object.keys(changed).filter((key) => !['options', 'alertable'].includes(key));
    }

    get isDirty() {
        return this.changedAttributeNames.length > 0 || this.optionsChanged || this.alertableChanged;
    }

    get dirtyCount() {
        return this.changedAttributeNames.length + (this.optionsChanged ? 1 : 0) + (this.alertableChanged ? 1 : 0);
    }

    snapshotOptions() {
        this.optionsSnapshot = JSON.stringify(this.model?.options ?? {});
        this.alertableSnapshot = JSON.stringify(this.model?.alertable ?? {});
        this.optionsRevision++;
    }

    @action touch() {
        this.optionsRevision++;
    }

    @action discardChanges() {
        this.model.rollbackAttributes();

        try {
            this.model.set('options', JSON.parse(this.optionsSnapshot));
            this.model.set('alertable', JSON.parse(this.alertableSnapshot));
        } catch {
            // snapshots are JSON we wrote ourselves
        }

        this.optionsRevision++;
    }

    @action async copyNetworkKey() {
        const key = this.model?.key;

        if (!key) {
            return;
        }

        try {
            await navigator.clipboard.writeText(key);
            this.notifications.success(this.intl.t('storefront.settings.sections.network-key-copied'));
        } catch {
            this.notifications.info(key);
        }
    }

    /**
     * Alias for model.gateways, representing the gateways associated with the network.
     *
     * @property {Array} gateways
     */
    @alias('model.gateways') gateways;

    /**
     * Alias for model.notification_channels, representing the notification channels associated with the network.
     *
     * @property {Array} channels
     */
    @alias('model.notification_channels') channels;

    /**
     * Save network settings.
     *
     * @method saveSettings
     * @public
     */
    @action saveSettings(event) {
        event?.preventDefault?.();
        this.isLoading = true;

        this.model
            .save()
            .then(() => {
                this.snapshotOptions();
                this.notifications.success(this.intl.t('storefront.networks.index.network.index.change-network-saved'));
            })
            .catch((error) => {
                this.notifications.serverError(error);
            })
            .finally(() => {
                this.isLoading = false;
            });
    }

    /**
     * Upload a file.
     *
     * @method uploadFile
     * @param {String} type - Type of the file.
     * @param {File} file - File to upload.
     * @public
     */
    @action uploadFile(type, file) {
        const prefix = type.replace('storefront_', '');

        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/storefront/${this.model.id}/${type}`,
                key_uuid: this.model.id,
                key_type: `storefront:network`,
                type,
            },
            (uploadedFile) => {
                this.model.setProperties({
                    [`${prefix}_uuid`]: uploadedFile.id,
                    [`${prefix}_url`]: uploadedFile.url,
                    [prefix]: uploadedFile,
                });
            }
        );
    }

    /**
     * Create a new payment gateway.
     *
     * @method createGateway
     * @public
     */
    @action createGateway() {
        const gateway = this.store.createRecord('gateway', {
            owner_uuid: this.model.id,
            owner_type: 'storefront:network',
        });

        this.editGateway(gateway, {
            title: this.intl.t('storefront.networks.index.network.index.create-new-payment-gateway'),
            acceptButtonText: this.intl.t('storefront.networks.index.network.index.save-gateway'),
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await gateway.save();
                    this.notifications.success(this.intl.t('storefront.networks.index.network.index.new-gateway-add-network'));
                    this.gateways.pushObject(gateway);
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
            decline: (modal) => {
                gateway.destroyRecord();
                modal.done();
            },
        });
    }

    /**
     * Edit a payment gateway.
     *
     * @method editGateway
     * @param {Object} gateway - The gateway object to edit.
     * @param {Object} [options={}] - Optional parameters for editing the gateway.
     * @public
     */
    @action editGateway(gateway, options = {}) {
        if (options === null) {
            options = {};
        }

        // The settings controller's modal saves the gateway and reports errors; only the message differs.
        if (!options.successNotification) {
            options.successNotification = this.intl.t('storefront.networks.index.network.index.payment-gateway-changes-success');
        }

        return this.gatewaysController.editGateway(gateway, options);
    }

    /**
     * Delete a payment gateway.
     *
     * @method deleteGateway
     * @public
     */
    @action deleteGateway() {
        return this.gatewaysController.deleteGateway(...arguments);
    }

    /**
     * Create a new notification channel.
     *
     * @method createChannel
     * @public
     */
    @action createChannel() {
        const channel = this.store.createRecord('notification-channel', {
            owner_uuid: this.model.id,
            owner_type: 'storefront:network',
        });

        this.editChannel(channel, {
            title: this.intl.t('storefront.networks.index.network.index.create-new-notification-channel'),
            acceptButtonText: this.intl.t('storefront.networks.index.network.index.create-notification-channel'),
            confirm: (modal) => {
                modal.startLoading();

                return channel.save().then((channel) => {
                    this.notifications.success(this.intl.t('storefront.networks.index.network.index.notification-channel-add-network'));
                    this.channels.pushObject(channel);
                });
            },
            decline: (modal) => {
                channel.destroyRecord();
                modal.done();
            },
        });
    }

    /**
     * Edit a notification channel.
     *
     * @method editChannel
     * @param {Object} channel - The channel object to edit.
     * @param {Object} [options={}] - Optional parameters for editing the channel.
     * @public
     */
    @action editChannel(channel, options = {}) {
        if (options === null) {
            options = {};
        }

        if (!options.confirm) {
            options.confirm = (modal) => {
                modal.startLoading();

                return channel.save().then(() => {
                    this.notifications.success(this.intl.t('storefront.networks.index.network.index.notification-channel-changes-save'));
                });
            };
        }

        return this.notificationsController.editChannel(channel, options);
    }

    /**
     * Delete a notification channel.
     *
     * @method deleteChannel
     * @public
     */
    @action deleteChannel() {
        return this.notificationsController.deleteChannel(...arguments);
    }

    /**
     * Send a test push notification through a notification channel.
     *
     * @method testChannel
     * @public
     */
    @action testChannel() {
        return this.notificationsController.testChannel(...arguments);
    }

    /**
     * Make an alertable action.
     *
     * @method makeAlertable
     * @param {String} reason - Reason for the alert.
     * @param {Array} models - Models associated with the alert.
     * @public
     */
    @action makeAlertable(reason, models) {
        if (!this.model.alertable || !this.model.alertable?.length) {
            this.model.set('alertable', {});
        }

        const serializedModels = models.map((model) => {
            return model.serialize();
        });

        this.model.set(`alertable.${reason}`, serializedModels);
    }
}
