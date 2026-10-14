import Component from '@glimmer/component';
import { get } from '@ember/object';

/**
 * A customer on the shared card anatomy: the customer pill as identity, contact facts
 * in the body (no filler image), account state and actions in the footer.
 */
export default class StorefrontCustomerCardComponent extends Component {
    get customer() {
        return this.args.customer ?? this.args.resource;
    }

    get placesCount() {
        const places = get(this.customer, 'places');

        return places?.length ?? 0;
    }
}
