import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import SocketListeners from '../../../utils/socket-listeners';

/**
 * The order panel's body: one scroll instead of tabs. Reads what the order record does not
 * carry (payment, due time, alerts, what an edit may change) from the order's overview.
 */
export default class StorefrontOrderOverviewComponent extends Component {
    @service fetch;
    @service intl;
    @service abilities;
    @service notifications;
    @service storefrontOrderActions;
    @service socket;
    @service currentUser;
    @tracked overview = null;
    @tracked flow = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        clearTimeout(this.threadTimer);
        this.live?.stop();
        this.live = null;
    }

    /**
     * Messages arrive over the socket while the panel is open: no polling. Each of the order's
     * threads is read on its own channel (`chat.{uuid}`), which tells which thread a message is
     * in whoever sent it (the customer app's messages carry no channel id). The company channel
     * says when the order gets a thread it did not have.
     */
    listen() {
        if (!this.socket?.instance) {
            return;
        }
        this.live = this.live ?? new SocketListeners(this.socket);

        const companyId = this.currentUser?.companyId ?? this.order?.company_uuid;
        if (companyId) {
            this.live.listen(`company.${companyId}`, (message) => this.onCompanyEvent(message));
        }
        const channels = this.overview?.chat?.channels ?? {};
        Object.keys(channels).forEach((kind) => {
            if (channels[kind]?.uuid) {
                this.live.listen(`chat.${channels[kind].uuid}`, (message) => this.onThreadEvent(kind, message));
            }
        });
    }

    onCompanyEvent({ event, data } = {}) {
        // A thread this order did not have yet: learn it (and any unread) from the overview.
        const orderUuid = this.order?.id ?? this.order?.uuid;
        if (event === 'chat_channel.created' && orderUuid && data?.meta?.storefront_order_uuid === orderUuid) {
            // The thread's kind as the channel is tagged: `customer`, `driver` (dispatch), else the
            // customer-and-driver thread.
            const kind = { customer: 'customer', driver: 'dispatch' }[data.meta.kind] ?? 'group';
            const before = this.overview?.chat?.unread?.[kind] ?? 0;
            this.reload();
            // Its first message can come before the panel listens on it: look again, and say so.
            clearTimeout(this.threadTimer);
            this.threadTimer = setTimeout(async () => {
                if (this.isDestroying) {
                    return;
                }
                await this.load.perform();
                const panel = this.storefrontOrderActions.panel;
                if ((this.overview?.chat?.unread?.[kind] ?? 0) > before && !(panel.chatOpen && panel.chatKind === kind)) {
                    this.notifications.info(this.intl.t('storefront.order.chat.new-message', { name: this.senderName(kind) }));
                }
            }, 3000);
        }
    }

    onThreadEvent(kind, { event, data } = {}) {
        if (event !== 'chat_message.created') {
            return;
        }
        const panel = this.storefrontOrderActions.panel;
        if (panel.chatOpen && panel.chatKind === kind) {
            // The open thread shows it (and marks it read).
            panel.chatArrived?.(kind);
            return;
        }
        // Sent from this console by this user (the console API names the sender by uuid; the
        // apps by public id, so a message sent from the driver app still counts as the driver's).
        const mine = data?.sender?.user_uuid && data.sender.user_uuid === this.currentUser?.id;
        if (mine || data?.system) {
            return;
        }

        panel.addUnread(kind);
        this.notifications.info(this.intl.t('storefront.order.chat.new-message', { name: this.senderName(kind, data) }));
        // The latest-message preview and the exact counts.
        this.reload();
    }

    senderName(kind, data = {}) {
        const first = (name) => String(name ?? '').split(' ')[0];
        if (kind === 'customer') {
            return first(this.order?.customer?.name) || this.intl.t('storefront.common.customer');
        }
        if (kind === 'dispatch') {
            return first(this.order?.driver_assigned?.name) || this.intl.t('storefront.order.panel.driver');
        }

        return first(data?.sender?.name) || this.intl.t('storefront.order.panel.all-three');
    }

    /** The order, passed as `@order` by the order route or as `@resource` by the context panel. */
    get order() {
        return this.args.order ?? this.args.resource;
    }

    @task({ restartable: true }) *load() {
        if (!this.order?.public_id) {
            return;
        }

        try {
            const [overview, flow] = yield Promise.all([
                this.fetch.get(`orders/${this.order.public_id}/overview`, {}, { namespace: 'storefront/int/v1' }),
                this.fetch.get(`orders/${this.order.public_id}/flow`, {}, { namespace: 'storefront/int/v1' }).catch(() => null),
            ]);
            this.overview = overview;
            this.flow = flow;
            const panel = this.storefrontOrderActions.panel;
            panel.setOverview(this.order.public_id, overview);
            panel.flow = flow;
            panel.refresh = this.refresh;
            panel.reloadOverview = this.reload;
            panel.printLabel = () => this.printLabel.perform();
            this.listen();
        } catch {
            this.overview = null;
        }
    }

    @action reload() {
        this.load.perform();
    }

    @action refresh() {
        this.load.perform();
        return (this.args.refresh ?? this.args.overlay?.onChange)?.();
    }

    get entities() {
        const entities = this.order?.payload?.entities;

        return entities?.toArray?.() ?? Array.from(entities ?? []);
    }

    get canEdit() {
        return this.abilities.can('storefront update order') && this.overview?.edit?.stage !== 'closed';
    }

    get canMarkUnavailable() {
        return this.abilities.can('storefront update order') && ['open', 'ready'].includes(this.overview?.edit?.stage);
    }

    @action edit(focus) {
        return this.storefrontOrderActions.editOrder(this.order, { focus });
    }

    /** An item the store cannot supply (or can again); the order's alerts follow. */
    @action async setUnavailable(entity, value) {
        const previous = entity.meta ?? {};
        entity.set('meta', { ...previous, unavailable: value });

        try {
            const response = await this.fetch.patch(`orders/${this.order.public_id}/entities/${entity.public_id}`, { unavailable: value }, { namespace: 'storefront/int/v1' });
            if (response?.customer_told) {
                const name = String(this.order.customer?.name ?? '').split(' ')[0] || this.intl.t('storefront.order.edit.the-customer');
                this.notifications.info(this.intl.t('storefront.order.packing.unavailable-told', { item: entity.name, name }));
            }
            this.load.perform();
        } catch (error) {
            entity.set('meta', previous);
            this.notifications.serverError(error);
        }
    }

    /** The shipping label as a PDF in a new tab. */
    @task *printLabel() {
        this.storefrontOrderActions.panel.printingLabel = true;
        try {
            const { data } = yield this.fetch.get(`orders/label/${this.order.public_id}`, { format: 'base64' }, { namespace: 'storefront/int/v1' });
            const bytes = Uint8Array.from(atob(data), (character) => character.charCodeAt(0));
            const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
            window.open(url, '_blank', 'noopener');
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.storefrontOrderActions.panel.printingLabel = false;
        }
    }
}
