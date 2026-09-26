import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { alias } from '@ember/object/computed';
import { action, set } from '@ember/object';
import { capitalize } from '@ember/string';
import { tracked } from '@glimmer/tracking';
import getNotificationSchemas from '../../utils/get-notification-schemas';

export default class SettingsNotificationsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service store;
    @service intl;
    @service crud;
    @service storefront;
    @service hostRouter;
    @service fetch;
    @alias('storefront.activeStore') activeStore;
    queryParams = ['query'];

    @tracked query;

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

    @action testChannel(channel) {
        this.modalsManager.show('modals/test-notification-channel', {
            title: this.intl.t('storefront.settings.notification.send-test-push'),
            acceptButtonText: this.intl.t('storefront.settings.notification.send-test-push-button'),
            acceptButtonIcon: 'paper-plane',
            channel,
            token: '',
            environment: null,
            results: null,
            setToken: (event) => {
                this.modalsManager.setOption('token', event.target.value);
            },
            setEnvironment: (environment) => {
                this.modalsManager.setOption('environment', environment);
            },
            keepOpen: true,
            confirm: async (modal) => {
                const { token, environment } = this.modalsManager.getOptions(['token', 'environment']);
                modal.startLoading();

                try {
                    const response = await this.fetch.post(`notification-channels/${channel.id}/test`, { token, environment }, { namespace: 'storefront/int/v1' });
                    this.modalsManager.setOption('results', response.results);

                    if (response.status === 'OK') {
                        this.notifications.success(this.intl.t('storefront.settings.notification.test-push-sent'));
                    } else {
                        this.notifications.warning(this.intl.t('storefront.settings.notification.test-push-failed'));
                    }
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
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
