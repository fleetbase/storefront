import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { alias } from '@ember/object/computed';
import { action, set } from '@ember/object';
import { capitalize } from '@ember/string';
import getNotificationSchemas from '../../utils/get-notification-schemas';

export default class SettingsNotificationsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service store;
    @service intl;
    @service crud;
    @service storefront;
    @service hostRouter;
    @alias('storefront.activeStore') activeStore;

    @action createChannel() {
        const channel = this.store.createRecord('notification-channel', {
            owner_uuid: this.activeStore.id,
            owner_type: 'storefront:store',
        });

        this.editChannel(channel, {
            title: this.intl.t('storefront.settings.notification.create-new-notification-channel'),
            acceptButtonText: this.intl.t('storefront.settings.notification.create-notification-channel'),
            decline: (modal) => {
                channel.destroyRecord();
                modal.done();
            },
        });
    }

    @action editChannel(channel, options = {}) {
        const schemas = getNotificationSchemas();
        const schemaOptions = [
            { name: 'Apple Push Notification Service (APN)', value: 'apn' },
            { name: 'Firebase Cloud Messaging (FCM)', value: 'fcm' },
        ];

        this.modalsManager.show('modals/create-notification-channel', {
            title: this.intl.t('storefront.settings.notification.edit-notification-channel'),
            acceptButtonText: this.intl.t('storefront.settings.notification.save-changes'),
            schema: channel.id ? channel.config : null,
            schemas,
            schemaOptions,
            selectSchema: (schema) => {
                this.modalsManager.setOption('schema', schemas[schema]);

                channel.setProperties({
                    name: `${capitalize(schema)} Notification Channel`,
                    scheme: schema,
                    config: schemas[schema],
                });
            },
            setConfigKey: (key, value) => {
                // eslint-disable-next-line no-undef
                if (value instanceof Event) {
                    const eventValue = value.target.value;

                    set(channel.config, key, eventValue);
                    return;
                }

                set(channel.config, key, value);
            },
            channel,
            confirm: (modal) => {
                modal.startLoading();

                return channel
                    .save()
                    .then(() => {
                        this.notifications.success(this.intl.t('storefront.settings.notification.new-notification-channel-added'));
                        this.hostRouter.refresh();
                    })
                    .catch((error) => {
                        // gateway.rollbackAttributes();
                        modal.stopLoading();
                        this.notifications.serverError(error);
                    });
            },
            ...options,
        });
    }

    @action deleteChannel(channel) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.settings.notification.remove-this-notification-channel'),
            body: this.intl.t('storefront.settings.notification.application-websites-utillizing-channel'),
            confirm: (modal) => {
                modal.startLoading();

                return channel.destroyRecord().then(() => {
                    // justincase
                    this.hostRouter.refresh();
                    modal.stopLoading();
                });
            },
        });
    }
}
