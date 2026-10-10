import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { alias } from '@ember/object/computed';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import getPodMethods from '@fleetbase/console/utils/get-pod-methods';

export default class SettingsIndexController extends Controller {
    @service notifications;
    @service fetch;
    @service storefront;
    @service intl;
    @service hostRouter;
    @service modalsManager;
    @service store;

    @alias('storefront.activeStore') activeStore;
    /** The store's locations with their hours, handed over by the settings shell route. */
    @tracked locations = [];
    queryParams = ['query'];

    @tracked query;
    @tracked podMethods = getPodMethods();
    @tracked isLoading = false;
    @tracked uploadQueue = [];
    @tracked uploadedFiles = [];
    @tracked orderConfigs = [];
    @tracked optionsRevision = 0;
    optionsSnapshot = '{}';
    alertableSnapshot = '{}';

    sectionLabels = {
        name: 'general',
        description: 'general',
        tags: 'general',
        currency: 'general',
        order_config_uuid: 'general',
        phone: 'general',
        email: 'general',
        website: 'general',
        facebook: 'general',
        instagram: 'general',
        twitter: 'general',
        logo_uuid: 'branding',
        backdrop_uuid: 'branding',
        online: 'checkout-rules',
        pod_method: 'checkout-rules',
    };

    get memberNetworks() {
        return this.model?.networks ?? [];
    }

    get optionsChanged() {
        // read so the getter recomputes after setOption / touch
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
        return Boolean(this.model?.isNew) || this.changedAttributeNames.length > 0 || this.optionsChanged || this.alertableChanged;
    }

    get dirtyCount() {
        return this.changedAttributeNames.length + (this.optionsChanged ? 1 : 0) + (this.alertableChanged ? 1 : 0);
    }

    get dirtySections() {
        const sections = new Set(this.changedAttributeNames.map((key) => this.sectionLabels[key] ?? 'general'));

        if (this.optionsChanged) {
            sections.add('checkout-rules');
        }

        if (this.alertableChanged) {
            sections.add('alerts');
        }

        return [...sections].map((section) => this.intl.t(`storefront.settings.sections.${section}`));
    }

    snapshotOptions() {
        this.optionsSnapshot = JSON.stringify(this.model?.options ?? {});
        this.alertableSnapshot = JSON.stringify(this.model?.alertable ?? {});
        this.optionsRevision++;
    }

    @action touch() {
        this.optionsRevision++;
    }

    @action setOption(key, value) {
        if (!this.model.options || typeof this.model.options !== 'object') {
            this.model.set('options', {});
        }

        this.model.set(`options.${key}`, value);
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

    @action addTag(tag) {
        if (!isArray(this.model.tags)) {
            this.model.tags = [];
        }

        this.model.tags?.pushObject(tag);
    }

    @action removeTag(index) {
        this.model.tags?.removeAt(index);
    }

    @action editHours() {
        return this.hostRouter.transitionTo('console.storefront.settings.locations');
    }

    /**
     * Deletes the store after confirmation, then lands on the next store the
     * organisation has or on the first-store flow when none is left.
     */
    @action deleteStore() {
        const store = this.model;

        this.modalsManager.confirm({
            title: this.intl.t('storefront.settings.danger.confirm-title', { name: store.name }),
            body: this.intl.t('storefront.settings.danger.confirm-body'),
            acceptButtonText: this.intl.t('storefront.settings.danger.delete-button'),
            acceptButtonType: 'danger',
            acceptButtonIcon: 'trash',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await store.destroyRecord();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                    return;
                }

                this.notifications.success(this.intl.t('storefront.settings.danger.deleted', { name: store.name }));

                const remaining = this.store.peekAll('store').filter((record) => !record.isDeleted && record.id !== store.id);

                if (remaining.length) {
                    this.storefront.setActiveStorefront(remaining[0]);
                    await this.hostRouter.transitionTo('console.storefront.home');
                    return this.hostRouter.refresh();
                }

                await this.hostRouter.transitionTo('console.storefront.home');
                return this.storefront.createFirstStore();
            },
        });
    }

    @action saveSettings(event) {
        event?.preventDefault?.();
        this.isLoading = true;

        this.model
            .save()
            .then(() => {
                this.snapshotOptions();
                this.notifications.success(this.intl.t('storefront.settings.save-bar.saved-toast'));
            })
            .catch((error) => {
                this.notifications.serverError(error);
            })
            .finally(() => {
                this.isLoading = false;
            });
    }

    @action uploadFile(type, file) {
        const prefix = type.replace('storefront_', '');

        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/storefront/${this.activeStore.id}/${type}`,
                key_uuid: this.activeStore.id,
                key_type: 'storefront:store',
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

    @action queueFile(file) {
        this.uploadQueue.pushObject(file);
        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/storefront/${this.activeStore.id}/media`,
                key_uuid: this.activeStore.id,
                key_type: 'storefront:store',
                type: `storefront_store_media`,
            },
            (uploadedFile) => {
                this.model.files.pushObject(uploadedFile);
                this.uploadQueue.removeObject(file);
            },
            () => {
                this.uploadQueue.removeObject(file);
            }
        );
    }

    @action removeFile(file) {
        if (file.queue) {
            file.queue.remove(file);
        }

        if (file.model) {
            this.uploadedFiles.removeObject(file.model);
            file.model.destroyRecord();
        }

        this.uploadQueue.removeObject(file);
    }

    @action makeAlertable(reason, models) {
        if (!this.model.alertable || !this.model.alertable?.length) {
            this.model.set('alertable', {});
        }

        const serializedModels = models.map((model) => {
            return model.serialize();
        });

        this.model.set(`alertable.${reason}`, serializedModels);
        this.optionsRevision++;
    }
}
