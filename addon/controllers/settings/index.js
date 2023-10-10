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

    @alias('storefront.activeStore') activeStore;
    @tracked podMethods = getPodMethods();
    @tracked isLoading = false;
    @tracked uploadQueue = [];
    @tracked uploadedFiles = [];

    @action addTag(tag) {
        if (!isArray(this.model.tags)) {
            this.model.tags = [];
        }

        this.model.tags?.pushObject(tag);
    }

    @action removeTag(index) {
        this.model.tags?.removeAt(index);
    }

    @action saveSettings() {
        this.isLoading = true;

        this.model
            .save()
            .then(() => {
                this.notifications.success('Changes saved');
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

        this.model.set(`alertable.${reason}`, models);
    }
}
