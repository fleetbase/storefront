import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * A product's stock, edited on its own: turning tracking on or off and saving counts take effect
 * at once and are logged, apart from the product's other fields.
 */
export default class StorefrontProductInventoryComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service abilities;
    @tracked data = null;
    @tracked rows = [];
    @tracked edits = {};
    @tracked note = '';

    constructor() {
        super(...arguments);
        if (!this.args.product?.isNew) {
            this.load.perform();
        }
    }

    get product() {
        return this.args.product;
    }

    get cannotEdit() {
        return this.abilities.cannot('storefront update product');
    }

    get tracking() {
        return this.data ? this.data.track_inventory : Boolean(this.product?.track_inventory);
    }

    get summary() {
        return this.data?.summary ?? {};
    }

    get movements() {
        return this.data?.movements ?? [];
    }

    get isDirty() {
        return Object.keys(this.edits).length > 0;
    }

    apply(data) {
        this.data = data;
        this.edits = {};
        this.rows = (data?.locations ?? []).map((row) => ({ ...row }));
    }

    @task *load() {
        try {
            this.apply(yield this.fetch.get(`products/${this.product.id}/inventory`, {}, { namespace: 'storefront/int/v1' }));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *setTracking(enabled) {
        try {
            this.apply(yield this.fetch.put(`products/${this.product.id}/inventory`, { track_inventory: enabled }, { namespace: 'storefront/int/v1' }));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action setValue(row, field, event) {
        const raw = event.target.value;
        const value = raw === '' ? null : parseInt(raw, 10);
        this.edits = { ...this.edits, [row.location]: { ...(this.edits[row.location] ?? {}), location: row.location, [field]: Number.isNaN(value) ? null : value } };
    }

    @action setBackorder(row, enabled) {
        this.rows = this.rows.map((item) => (item.location === row.location ? { ...item, allow_backorder: enabled } : item));
        this.edits = { ...this.edits, [row.location]: { ...(this.edits[row.location] ?? {}), location: row.location, allow_backorder: enabled } };
    }

    @task *save() {
        const locations = Object.values(this.edits).map((edit) => {
            const row = { ...edit };
            if (row.quantity === null) {
                delete row.quantity;
            }
            return row;
        });

        try {
            this.apply(yield this.fetch.put(`products/${this.product.id}/inventory`, { locations, note: this.note || null }, { namespace: 'storefront/int/v1' }));
            this.note = '';
            this.notifications.success(this.intl.t('storefront.products.inventory.saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
