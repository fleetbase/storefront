import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * The customer's saved places: set the default, open one in Fleet-Ops. Places are
 * Fleet-Ops places, so the pill opens the Fleet-Ops place panel on demand.
 */
export default class CustomerPanelPlacesComponent extends Component {
    @service notifications;
    @service intl;

    get customer() {
        return this.args.customer;
    }

    get places() {
        const places = this.customer?.places;

        return places?.toArray?.() ?? Array.from(places ?? []);
    }

    get defaultPlaceId() {
        return this.customer?.place_uuid ?? this.customer?.place?.id ?? null;
    }

    get rows() {
        return this.places.map((place) => ({ place, isDefault: place.id === this.defaultPlaceId }));
    }

    @task *setDefault(place) {
        try {
            this.customer.setProperties({ place, place_uuid: place.id });
            yield this.customer.save();
            this.notifications.success(this.intl.t('storefront.customers.panel.default-saved', { name: place.name ?? place.address }));
            this.args.onChange?.();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action noop() {}
}
