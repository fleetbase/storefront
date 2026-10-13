import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

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
    @tracked overview = null;
    @tracked flow = null;

    constructor() {
        super(...arguments);
        this.load.perform();
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
            panel.printLabel = () => this.printLabel.perform();
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
            await this.fetch.patch(`orders/${this.order.public_id}/entities/${entity.public_id}`, { unavailable: value }, { namespace: 'storefront/int/v1' });
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
