import Component from '@glimmer/component';
import { get } from '@ember/object';

/**
 * A network on the shared card anatomy: name and online state in the header,
 * the logo on its backdrop as media, and the member count, currency and
 * actions in the footer.
 */
export default class StorefrontNetworkCardComponent extends Component {
    get network() {
        return this.args.network ?? this.args.resource;
    }

    get isOnline() {
        return Boolean(get(this.network, 'online'));
    }

    get storesCount() {
        const count = get(this.network, 'stores_count');

        if (typeof count === 'number') {
            return count;
        }

        const stores = get(this.network, 'stores');

        return stores ? stores.length : 0;
    }
}
