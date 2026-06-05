import Component from '@glimmer/component';

export default class StorefrontOrderDetailsCustomerInsightsComponent extends Component {
    get customer() {
        return this.args.resource?.customer;
    }

    get orderCount() {
        return Number(this.customer?.orders ?? this.customer?.order_count ?? 0);
    }

    get hasCompleteContact() {
        return Boolean(this.customer?.phone && this.customer?.email);
    }

    get customerType() {
        if (!this.customer) {
            return null;
        }

        return this.orderCount > 1 ? 'Returning customer' : 'First-time customer';
    }

    get contactStatus() {
        if (this.hasCompleteContact) {
            return 'Phone and email available';
        }

        if (this.customer?.phone) {
            return 'Phone available';
        }

        if (this.customer?.email) {
            return 'Email available';
        }

        return 'No contact details';
    }
}
