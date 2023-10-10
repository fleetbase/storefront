import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { alias } from '@ember/object/computed';
import { action, set } from '@ember/object';
import { capitalize } from '@ember/string';
import getNotificationSchemas from '../../utils/get-notification-schemas';

export default class SettingsNotificationsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service store;
    @service crud;
    @service storefront;
    @alias('storefront.activeStore') activeStore;
    @tracked channels = [];

    @action createChannel() {
        const channel = this.store.createRecord('notification-channel', {
            owner_uuid: this.activeStore.id,
            owner_type: 'storefront:store',
        });

        this.editChannel(channel, {
            title: `Create a new notification channel`,
            acceptButtonText: 'Create Notification Channel',
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
            title: `Edit notification channel`,
            acceptButtonText: 'Save Changes',
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
                    .then((channel) => {
                        this.notifications.success(`New notification channel added`);
                        this.channels.pushObject(channel);
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
            title: 'Are you sure you wish to remove this notification channel?',
            body: 'All applications and websites utilizing this channel in configuration could be disrupted if channel is not replaced beforehand',
            confirm: (modal) => {
                modal.startLoading();

                return channel.destroyRecord().then(() => {
                    // justincase
                    this.channels.removeObject(channel);
                    modal.stopLoading();
                });
            },
        });
    }
}
