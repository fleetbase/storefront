import Controller, { inject as controller } from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { alias } from '@ember/object/computed';
import { action } from '@ember/object';
import getPodMethods from '@fleetbase/ember-core/utils/get-pod-methods';

/**
 * NetworksIndexNetworkIndexController
 *
 * This controller handles the logic for managing networks, gateways, and notification channels.
 *
 * @class NetworksIndexNetworkIndexController
 * @extends Controller
 */
export default class NetworksIndexNetworkIndexController extends Controller {
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
    @action saveSettings() {
        this.isLoading = true;

        this.model
            .save()
            .then(() => {
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
            confirm: (modal) => {
                modal.startLoading();

                return gateway.save().then((gateway) => {
                    this.notifications.success(this.intl.t('storefront.networks.index.network.index.new-gateway-add-network'));
                    this.gateways.pushObject(gateway);
                });
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

        if (!options.confirm) {
            options.confirm = (modal) => {
                modal.startLoading();

                return gateway.save().then(() => {
                    this.notifications.success(this.intl.t('storefront.networks.index.network.index.payment-gateway-changes-success'));
                });
            };
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
                    this.notifications.success(this.intl.t('storefront.controllers.networks.index.notification-channel-changes-save'));
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

        this.model.set(`alertable.${reason}`, models);
    }
}
