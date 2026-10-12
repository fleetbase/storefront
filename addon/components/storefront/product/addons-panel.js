import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { all } from 'rsvp';

/**
 * The store's reusable add-on groups in the shared right-hand panel: the groups on the
 * left, the selected group's add-ons as an editable table on the right, one save bar.
 * Replaces the add-ons modal.
 */
export default class StorefrontProductAddonsPanelComponent extends Component {
    @service store;
    @service intl;
    @service notifications;
    @service modalsManager;

    @tracked groups = [];
    @tracked activeGroup = null;
    @tracked revision = 0;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get currency() {
        return this.args.store?.currency;
    }

    get addons() {
        this.revision;
        return (this.activeGroup?.addons?.toArray?.() ?? Array.from(this.activeGroup?.addons ?? [])).filter((addon) => !addon.isDeleted);
    }

    get groupRows() {
        this.revision;
        return this.groups.map((group) => ({ group, count: group.addons?.length ?? 0, isActive: group === this.activeGroup }));
    }

    get isDirty() {
        this.revision;
        return this.groups.some((group) => group.hasDirtyAttributes || (group.addons?.toArray?.() ?? []).some((addon) => addon.isNew || addon.hasDirtyAttributes));
    }

    touch() {
        this.revision++;
    }

    @task *load() {
        try {
            const groups = yield this.store.query('addon-category', { owner_uuid: this.args.store?.id, for: 'storefront_product_addon' });
            this.groups = groups.toArray?.() ?? Array.from(groups ?? []);
            this.activeGroup = this.groups[0] ?? null;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action selectGroup(group) {
        this.activeGroup = group;
    }

    @task *createGroup() {
        const group = this.store.createRecord('addon-category', {
            name: this.intl.t('storefront.products.addons.untitled-group'),
            for: 'storefront_product_addon',
            owner_type: 'storefront:store',
            owner_uuid: this.args.store?.id,
        });

        try {
            yield group.save();
            this.groups = [...this.groups, group];
            this.activeGroup = group;
        } catch (error) {
            group.rollbackAttributes();
            this.notifications.serverError(error);
        }
    }

    @action deleteGroup(group) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.products.addons.delete-group-title', { name: group.name }),
            body: this.intl.t('storefront.products.addons.delete-group-body'),
            acceptButtonText: this.intl.t('storefront.common.delete'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await group.destroyRecord();
                    this.groups = this.groups.filter((item) => item !== group);
                    this.activeGroup = this.groups[0] ?? null;
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action addAddon() {
        if (!this.activeGroup) {
            return;
        }

        const addon = this.store.createRecord('product-addon', { category_uuid: this.activeGroup.id });
        this.activeGroup.addons.pushObject(addon);
        this.touch();
    }

    @task *removeAddon(addon) {
        this.activeGroup.addons.removeObject(addon);
        this.touch();

        if (addon.isNew) {
            addon.unloadRecord();
            return;
        }

        try {
            yield addon.destroyRecord();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action changed() {
        this.touch();
    }

    @task *save() {
        try {
            yield all(
                this.groups
                    .filter((group) => group.hasDirtyAttributes || (group.addons?.toArray?.() ?? []).some((addon) => addon.isNew || addon.hasDirtyAttributes))
                    .map((group) => group.save())
            );
            this.notifications.success(this.intl.t('storefront.products.addons.saved'));
            this.touch();
            this.args.onSaved?.();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action discard() {
        for (const group of this.groups) {
            for (const addon of group.addons?.toArray?.() ?? []) {
                if (addon.isNew) {
                    group.addons.removeObject(addon);
                    addon.unloadRecord();
                } else {
                    addon.rollbackAttributes();
                }
            }
            group.rollbackAttributes();
        }
        this.touch();
    }

    @action close() {
        this.discard();
        this.args.onClose?.();
    }
}
