import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * An order's chats in its panel. The store writes to the customer in their own thread (the
 * customer sees it in the app as "Message the store"); the customer's thread with the driver can
 * be read here but is theirs to write in.
 */
export default class StorefrontOrderChatComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @tracked kind = 'customer';
    @tracked chat = null;
    @tracked canWrite = false;
    @tracked quickReplies = [];
    @tracked reason = null;
    @tracked draft = '';
    loaded = false;

    get order() {
        return this.args.order;
    }

    /** Open from the start when the customer wrote something the store has not read. */
    get startOpen() {
        return (this.args.unread ?? 0) > 0;
    }

    get subtitle() {
        const unread = this.args.unread ?? 0;
        return unread > 0 ? this.intl.t('storefront.order.chat.unread', { count: unread }) : null;
    }

    get kindOptions() {
        return [
            { id: 'customer', label: this.intl.t('storefront.order.chat.with-customer') },
            { id: 'driver', label: this.intl.t('storefront.order.chat.customer-and-driver') },
        ];
    }

    get messages() {
        return this.chat?.messages ?? [];
    }

    get emptyText() {
        if (this.kind === 'driver' && this.reason === 'driver_not_assigned') {
            return this.intl.t('storefront.order.chat.no-driver');
        }
        return this.intl.t('storefront.order.chat.empty');
    }

    @action toggled(isOpen) {
        if (isOpen && !this.loaded) {
            this.load.perform();
        }
    }

    @action setKind(kind) {
        this.kind = kind;
        this.load.perform();
    }

    @task({ restartable: true }) *load() {
        this.loaded = true;
        try {
            const response = yield this.fetch.get(`orders/${this.order.public_id}/chat/${this.kind}`, {}, { namespace: 'storefront/int/v1' });
            this.chat = response?.chat ?? null;
            this.reason = response?.reason ?? null;
            this.canWrite = Boolean(response?.can_write);
            this.quickReplies = response?.quick_replies ?? [];

            if (this.chat?.unread_count) {
                yield this.fetch.post(`orders/${this.order.public_id}/chat/${this.kind}/read`, {}, { namespace: 'storefront/int/v1' });
                this.args.onRead?.();
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *send() {
        const content = this.draft.trim();
        if (!content) {
            return;
        }

        try {
            const { message } = yield this.fetch.post(`orders/${this.order.public_id}/chat/customer/messages`, { content }, { namespace: 'storefront/int/v1' });
            this.chat = { ...(this.chat ?? {}), messages: [...this.messages, message] };
            this.draft = '';
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action submit(event) {
        event.preventDefault();
        this.send.perform();
    }

    /** Enter sends; Shift+Enter starts a new line. */
    @action keydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.send.perform();
        }
    }

    @action useQuickReply(reply) {
        this.draft = reply;
    }

    @action scrollToEnd(element) {
        element.scrollTop = element.scrollHeight;
    }

    constructor() {
        super(...arguments);
        if (this.startOpen) {
            this.load.perform();
        }
    }
}
