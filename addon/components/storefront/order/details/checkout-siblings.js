import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

/**
 * The other orders placed in the same checkout: a multi-store purchase creates one per store.
 */
export default class StorefrontOrderDetailsCheckoutSiblingsComponent extends Component {
    @service fetch;
    @service storefrontOrderActions;
    @tracked siblings = [];

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get checkoutId() {
        return this.args.resource?.meta?.checkout_id;
    }

    @task *load() {
        if (!this.checkoutId) {
            return;
        }

        try {
            const orders = yield this.fetch.get('orders', { query: this.checkoutId, limit: 25 }, { namespace: 'storefront/int/v1', normalizeToEmberData: true, normalizeModelType: 'order' });
            const list = orders?.toArray?.() ?? Array.from(orders ?? []);
            this.siblings = list.filter((order) => order.meta?.checkout_id === this.checkoutId && order.id !== this.args.resource.id);
        } catch {
            this.siblings = [];
        }
    }
}
