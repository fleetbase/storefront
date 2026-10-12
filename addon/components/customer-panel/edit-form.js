import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Edits a customer's details inside their panel: name, title, contact details and the
 * internal id, with the shared save bar. Places keep their own tab.
 */
export default class CustomerPanelEditFormComponent extends Component {
    @service intl;
    @service notifications;

    get customer() {
        return this.args.customer;
    }

    get isDirty() {
        return Boolean(this.customer?.hasDirtyAttributes);
    }

    @task *save() {
        if (!String(this.customer?.name ?? '').trim()) {
            return this.notifications.warning(this.intl.t('storefront.customers.panel.name-required'));
        }

        try {
            yield this.customer.save();
            this.notifications.success(this.intl.t('storefront.customers.panel.saved', { name: this.customer.name }));
            this.args.onDone?.(this.customer);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action cancel() {
        this.customer?.rollbackAttributes?.();
        this.args.onDone?.();
    }
}
