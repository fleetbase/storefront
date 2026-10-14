import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { htmlSafe } from '@ember/template';
import { task, timeout } from 'ember-concurrency';
import { format, isValid, parseISO } from 'date-fns';

const WIDTH = 360;

/**
 * The 11a messages drawer: a 360px sheet beside the order panel with the order's three threads
 * (store and customer, store and driver, all three). Rendered into the page body so the panel's
 * overlay does not clip it; it follows the panel's left edge as the panel is resized.
 */
export default class StorefrontOrderPanelChatDrawerComponent extends Component {
    @service fetch;
    @service intl;
    @service storefrontOrderActions;
    @service abilities;
    @service notifications;
    @tracked response = null;
    @tracked rect = null;
    @tracked destination = null;
    observer = null;

    get panel() {
        return this.storefrontOrderActions.panel;
    }

    get order() {
        return this.args.order;
    }

    @action setupAnchor(element) {
        this.destination = document.body;
        this.panelElement = element.closest('.next-content-overlay-panel');
        const measure = () => {
            const box = this.panelElement?.getBoundingClientRect();
            this.rect = box ? { top: box.top, left: box.left, height: box.height } : null;
        };
        measure();
        this.measure = measure;
        if (this.panelElement && window.ResizeObserver) {
            this.observer = new ResizeObserver(measure);
            this.observer.observe(this.panelElement);
        }
        window.addEventListener('resize', measure);
        // New messages come over the socket (the panel's overview listens): reload the thread shown.
        this.panel.chatArrived = (kind) => kind === this.panel.chatKind && this.load.perform();
        this.watch.perform();
    }

    @action teardownAnchor() {
        this.observer?.disconnect();
        window.removeEventListener('resize', this.measure);
        this.panel.chatArrived = null;
        this.panel.closeChat();
    }

    get position() {
        const rect = this.rect;
        if (!rect) {
            return htmlSafe(`width: ${WIDTH}px; right: 0; top: 0; bottom: 0;`);
        }
        const left = Math.max(8, rect.left - WIDTH - 8);
        return htmlSafe(`width: ${WIDTH}px; left: ${left}px; top: ${rect.top + 8}px; height: ${rect.height - 16}px;`);
    }

    get customerName() {
        return String(this.order?.customer?.name ?? this.intl.t('storefront.common.customer')).split(' ')[0];
    }

    get driverName() {
        return String(this.order?.driver_assigned?.name ?? '').split(' ')[0];
    }

    get tabs() {
        const unread = this.panel.unread ?? {};
        const kind = this.panel.chatKind;
        return [
            { id: 'customer', label: this.customerName, unread: unread.customer, active: kind === 'customer' },
            {
                id: 'dispatch',
                label: this.driverName ? this.intl.t('storefront.order.panel.driver-tab', { name: this.driverName }) : this.intl.t('storefront.order.panel.driver'),
                unread: unread.dispatch,
                active: kind === 'dispatch',
            },
            { id: 'group', label: this.intl.t('storefront.order.panel.all-three'), unread: unread.group, active: kind === 'group' },
        ];
    }

    get messages() {
        return this.response?.chat?.messages ?? [];
    }

    get showSenders() {
        return this.panel.chatKind === 'group';
    }

    get canWrite() {
        return Boolean(this.response?.can_write) && this.abilities.can('storefront update order');
    }

    get quickReplies() {
        return this.response?.quick_replies ?? [];
    }

    get placeholder() {
        const kind = this.panel.chatKind;
        const name = kind === 'customer' ? this.customerName : kind === 'dispatch' ? this.driverName : this.intl.t('storefront.order.panel.everyone');
        return this.intl.t('storefront.order.panel.message-to', { name });
    }

    get emptyText() {
        const kind = this.panel.chatKind;
        if (kind === 'group' && !this.response?.chat) {
            return this.response?.reason === 'driver_not_assigned' ? this.intl.t('storefront.order.panel.group-no-driver') : this.intl.t('storefront.order.panel.group-hint');
        }
        if (kind === 'dispatch' && this.response?.reason === 'driver_not_assigned') {
            return this.intl.t('storefront.order.panel.assign-to-message');
        }
        return this.intl.t('storefront.order.chat.empty');
    }

    get footnote() {
        return this.panel.chatKind === 'dispatch' ? this.intl.t('storefront.order.panel.driver-footnote') : this.intl.t('storefront.order.panel.customer-footnote');
    }

    timeOf = (message) => {
        const at = message.created_at ? parseISO(message.created_at) : null;
        const time = at && isValid(at) ? format(at, 'HH:mm') : '';
        return message.is_mine || message.read ? time : `${time} · ${this.intl.t('storefront.order.panel.unread')}`;
    };

    @task({ restartable: true }) *load() {
        const kind = this.panel.chatKind;
        try {
            const response = yield this.fetch.get(`orders/${this.order.public_id}/chat/${kind}`, {}, { namespace: 'storefront/int/v1' });
            if (kind !== this.panel.chatKind) {
                return;
            }
            this.response = response;
            if (response?.chat?.unread_count) {
                yield this.fetch.post(`orders/${this.order.public_id}/chat/${kind}/read`, {}, { namespace: 'storefront/int/v1' });
                // Read now: what is on screen says so too.
                const messages = (response.chat.messages ?? []).map((message) => (message.is_mine ? message : { ...message, read: true }));
                this.response = { ...response, chat: { ...response.chat, messages, unread_count: 0 } };
                this.args.onRead?.();
            }
        } catch (error) {
            this.response = null;
            this.notifications.serverError(error);
        }
    }

    /** Loads a thread when the drawer opens on it or switches to it; new messages arrive over the socket. */
    @task *watch() {
        let loadedKind = null;
        while (true) {
            if (this.panel.chatOpen && this.panel.chatKind !== loadedKind) {
                loadedKind = this.panel.chatKind;
                this.measure?.();
                yield this.load.perform();
            } else if (!this.panel.chatOpen) {
                loadedKind = null;
            }
            yield timeout(300);
        }
    }

    @task *send() {
        const content = (this.panel.chatDraft ?? '').trim();
        if (!content) {
            return;
        }
        const kind = this.panel.chatKind;
        try {
            const { message } = yield this.fetch.post(`orders/${this.order.public_id}/chat/${kind}/messages`, { content }, { namespace: 'storefront/int/v1' });
            // Unless the socket's reload already brought it.
            const messages = this.messages.some((existing) => existing.id && existing.id === message?.id) ? this.messages : [...this.messages, message];
            this.response = { ...(this.response ?? {}), chat: { ...(this.response?.chat ?? {}), messages } };
            this.panel.chatDraft = '';
            if (!this.response.chat.id) {
                // The first message started the thread: load it (not waited on, a message arriving
                // over the socket reloads it too).
                this.load.perform();
            }
            // What the store says can settle a banner ("the customer has not been told"); the order
            // itself did not change, so the list stays as it is.
            this.panel.reloadOverview?.();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action pick(kind) {
        this.panel.chatKind = kind;
        this.response = null;
    }

    @action setDraft(event) {
        this.panel.chatDraft = event.target.value;
    }

    @action useReply(reply) {
        this.panel.chatDraft = reply;
    }

    @action submit(event) {
        event.preventDefault();
        this.send.perform();
    }

    @action scrollToEnd(element) {
        element.scrollTop = element.scrollHeight;
    }
}
