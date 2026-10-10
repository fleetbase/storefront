import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import isEmail from '@fleetbase/ember-core/utils/is-email';
import createShareableLink from '../../../utils/create-shareable-link';

/**
 * Invite stores to a network: by email, with a shareable link, or by adding a store the
 * user already administers. Replaces the share-network and add-stores-to-network modals.
 */
export default class StorefrontNetworkInvitePanelComponent extends Component {
    @service store;
    @service fetch;
    @service intl;
    @service notifications;
    @service currentUser;
    @tracked activeTab = this.args.activeTab ?? 'email';
    @tracked recipients = [];
    @tracked category = null;
    @tracked expiresInDays = 14;
    @tracked message = '';
    @tracked requireApproval = false;
    @tracked categories = [];
    @tracked members = [];
    @tracked ownStores = [];
    @tracked selectedStores = [];
    @tracked ownStoreCategory = null;

    tabs = ['email', 'link', 'own'];
    expiryOptions = [
        { value: 14, labelKey: 'storefront.networks.invitations.expires-14' },
        { value: 7, labelKey: 'storefront.networks.invitations.expires-7' },
        { value: 30, labelKey: 'storefront.networks.invitations.expires-30' },
        { value: 0, labelKey: 'storefront.networks.invitations.expires-never' },
    ];

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get network() {
        return this.args.network;
    }

    get tabItems() {
        return this.tabs.map((id) => ({ id, label: this.intl.t(`storefront.networks.invitations.tab-${id}`), isActive: id === this.activeTab }));
    }

    get expiryItems() {
        return this.expiryOptions.map((option) => ({ ...option, label: this.intl.t(option.labelKey) }));
    }

    get selectedExpiry() {
        return this.expiryItems.find((option) => option.value === this.expiresInDays) ?? this.expiryItems[0];
    }

    get validRecipients() {
        return this.recipients.filter((email) => isEmail(email));
    }

    get invalidRecipients() {
        return this.recipients.filter((email) => !isEmail(email));
    }

    get knownEmails() {
        const memberEmails = this.members.map((store) => store.email).filter(Boolean);
        const invitedEmails = (this.network.invitations ?? []).filter((invitation) => invitation.status === 'pending').map((invitation) => invitation.email);

        return new Set([...memberEmails, ...invitedEmails].map((email) => String(email).toLowerCase()));
    }

    get flaggedRecipients() {
        return this.recipients.filter((email) => this.knownEmails.has(String(email).toLowerCase()));
    }

    get sendableRecipients() {
        return this.validRecipients.filter((email) => !this.knownEmails.has(String(email).toLowerCase()));
    }

    get canSend() {
        return this.sendableRecipients.length > 0 && !this.send.isRunning;
    }

    get shareableLink() {
        return createShareableLink(`join/network/${this.network.public_id}`);
    }

    get shareableLinkEnabled() {
        return Boolean(this.network.options?.shareable_link_enabled);
    }

    get availableOwnStores() {
        const memberIds = new Set(this.members.map((store) => store.id));

        return this.ownStores.filter((store) => !memberIds.has(store.id));
    }

    get canAddOwnStores() {
        return this.selectedStores.length > 0 && !this.addOwnStores.isRunning;
    }

    @task *load() {
        try {
            const [categories, members, ownStores] = yield Promise.all([
                this.store.query('category', { owner_uuid: this.network.id, for: 'storefront_network', parents_only: true }),
                this.store.query('store', { network: this.network.id }),
                this.store.query('store', { limit: 300 }),
            ]);
            this.categories = categories?.toArray?.() ?? Array.from(categories ?? []);
            this.members = members?.toArray?.() ?? Array.from(members ?? []);
            this.ownStores = ownStores?.toArray?.() ?? Array.from(ownStores ?? []);
            if (!this.network.invitations) {
                yield this.network.loadInvitations();
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *send() {
        try {
            yield this.network.sendInvites({
                recipients: this.sendableRecipients,
                category_uuid: this.category?.id ?? null,
                expires_in_days: this.expiresInDays || null,
                message: this.message || null,
                require_approval: this.requireApproval,
            });
            this.notifications.success(this.intl.t('storefront.networks.invitations.sent', { count: this.sendableRecipients.length }));
            this.recipients = [];
            this.message = '';
            yield this.network.loadInvitations();
            this.args.onSent?.();
            this.args.onClose?.();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *addOwnStores() {
        try {
            yield this.network.addStores(this.selectedStores);
            if (this.ownStoreCategory) {
                for (const store of this.selectedStores) {
                    yield this.fetch.post(`networks/${this.network.id}/set-store-category`, { store: store.id, category: this.ownStoreCategory.id }, { namespace: 'storefront/int/v1' });
                }
            }
            this.notifications.success(this.intl.t('storefront.networks.invitations.own-stores-added', { count: this.selectedStores.length }));
            this.selectedStores = [];
            this.args.onSent?.();
            this.args.onClose?.();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *toggleShareableLink(enabled) {
        try {
            this.network.set('options', { ...(this.network.options ?? {}), shareable_link_enabled: enabled });
            yield this.network.save();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action selectTab(tab) {
        this.activeTab = tab.id ?? tab;
    }

    @action addRecipient(value) {
        const emails = String(value ?? '')
            .split(/[\s,;]+/)
            .map((email) => email.trim())
            .filter(Boolean);
        const next = [...this.recipients];

        emails.forEach((email) => {
            if (!next.includes(email)) {
                next.push(email);
            }
        });

        this.recipients = next;
    }

    @action removeRecipient(index) {
        this.recipients = this.recipients.filter((_, i) => i !== index);
    }

    @action setCategory(category) {
        this.category = category;
    }

    @action setOwnStoreCategory(category) {
        this.ownStoreCategory = category;
    }

    @action setExpiry(option) {
        this.expiresInDays = option?.value ?? 0;
    }

    @action setMessage(event) {
        this.message = event.target.value;
    }

    @action setRequireApproval(enabled) {
        this.requireApproval = enabled;
    }

    @action setSelectedStores(stores) {
        this.selectedStores = Array.from(stores ?? []);
    }

    @action close() {
        this.args.onClose?.();
    }
}
