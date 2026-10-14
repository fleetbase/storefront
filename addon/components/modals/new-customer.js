import Component from '@glimmer/component';
import { action } from '@ember/object';

/**
 * A customer's name, email and phone, entered while placing an order for them.
 */
export default class ModalsNewCustomerComponent extends Component {
    get draft() {
        return this.args.options?.draft;
    }

    @action setPhone(phone) {
        this.draft.phone = phone ?? '';
    }
}
