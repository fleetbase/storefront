import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';

const PACKING_STAGES = ['open', 'ready'];

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
    @service('universe/menu-service') menuService;
    @tracked overview = null;

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
            this.overview = yield this.fetch.get(`orders/${this.order.public_id}/overview`, {}, { namespace: 'storefront/int/v1' });
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

    get canPack() {
        return this.abilities.can('storefront update order') && PACKING_STAGES.includes(this.overview?.edit?.stage);
    }

    get itemsSubtitle() {
        const entities = this.entities;
        const count = entities.reduce((sum, entity) => sum + (Number(entity.meta?.quantity) || 1), 0);
        const products = this.intl.t('storefront.order.overview.items-count', { count, products: entities.length });
        if (!this.canPack || !entities.length) {
            return products;
        }

        const packed = entities.filter((entity) => entity.meta?.packed_at).length;
        return `${products} · ${this.intl.t('storefront.order.packing.packed-count', { packed, total: entities.length })}`;
    }

    get itemActions() {
        if (!this.abilities.can('storefront update order') || this.overview?.edit?.stage === 'closed') {
            return [];
        }

        return [{ text: this.intl.t('storefront.order.edit.edit-items'), icon: 'pen', size: 'xs', permission: 'storefront update order', onClick: () => this.edit('items') }];
    }

    /** Sections other extensions register on the order details, folded at the end. */
    get registeredSections() {
        const items = this.menuService.getMenuItems('storefront:component:order:details');

        return (isArray(items) ? items : []).map((item) => ({ slug: item.slug ?? item.id, title: item.title ?? item.label }));
    }

    @action edit(focus) {
        return this.storefrontOrderActions.editOrder(this.order, { focus });
    }

    /**
     * Mark a line packed or unavailable. The line updates at once; the overview is reloaded for
     * the alerts it raises.
     */
    @action async setPacking(entity, field, value) {
        const previous = entity.meta ?? {};
        const meta = { ...previous };
        if (field === 'packed') {
            meta.packed_at = value ? new Date().toISOString() : null;
        } else {
            meta[field] = value;
        }
        entity.set('meta', meta);

        try {
            await this.fetch.patch(`orders/${this.order.public_id}/entities/${entity.public_id}`, { [field]: value }, { namespace: 'storefront/int/v1' });
            this.load.perform();
        } catch (error) {
            entity.set('meta', previous);
            this.notifications.serverError(error);
        }
    }

    /** The shipping label as a PDF in a new tab. */
    @task *printLabel() {
        try {
            const { data } = yield this.fetch.get(`orders/label/${this.order.public_id}`, { format: 'base64' }, { namespace: 'storefront/int/v1' });
            const bytes = Uint8Array.from(atob(data), (character) => character.charCodeAt(0));
            const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
            window.open(url, '_blank', 'noopener');
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
