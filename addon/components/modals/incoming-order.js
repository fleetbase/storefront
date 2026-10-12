import Component from '@glimmer/component';
import { get } from '@ember/object';

function number(value) {
    const parsed = parseFloat(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

/**
 * The incoming order, read as a ticket: who placed it at which store, where it goes, what is in
 * it and what it came to, with accept and decline in the modal footer.
 */
export default class ModalsIncomingOrderComponent extends Component {
    get order() {
        return this.args.options?.order;
    }

    get store() {
        return this.args.options?.store;
    }

    get meta() {
        return this.order?.meta ?? {};
    }

    get isPickup() {
        return Boolean(this.meta.is_pickup);
    }

    get currency() {
        return this.meta.currency ?? this.store?.currency;
    }

    get total() {
        return number(this.meta.total ?? this.order?.transaction_amount);
    }

    get itemCount() {
        const entities = get(this.order ?? {}, 'payload.entities');
        const list = entities?.toArray?.() ?? Array.from(entities ?? []);

        return list.reduce((sum, entity) => sum + (number(get(entity, 'meta.quantity')) || 1), 0);
    }

    get isCash() {
        return Boolean(get(this.order ?? {}, 'payload.cod_amount')) || this.meta.gateway === 'cash';
    }

    get paymentLabel() {
        return this.isCash ? null : (this.meta.gateway ?? null);
    }

    /** A place on the route as one line: its name, then its address when the name is not the address. */
    placeLine(place) {
        if (!place) {
            return { name: null, address: null };
        }

        const name = get(place, 'name');
        const address = get(place, 'address') ?? get(place, 'street1');

        // Leave the name out when the address already starts with it ("T2" and "T2 - 26 Peace Avenue").
        const repeats = name && address && String(address).toLowerCase().startsWith(String(name).toLowerCase());

        return { name: name && !repeats ? name : null, address: address ?? name ?? null };
    }

    get pickup() {
        return this.placeLine(get(this.order ?? {}, 'payload.pickup'));
    }

    get dropoff() {
        return this.placeLine(get(this.order ?? {}, 'payload.dropoff'));
    }

    get driver() {
        const driver = get(this.order ?? {}, 'driver_assigned');

        return get(driver ?? {}, 'id') ? driver : null;
    }

    get customer() {
        return get(this.order ?? {}, 'customer');
    }

    get qrCode() {
        const code = get(this.order ?? {}, 'tracking_number.qr_code');

        return code ? `data:image/png;base64,${code}` : null;
    }

    get barcode() {
        const code = get(this.order ?? {}, 'tracking_number.barcode');

        return code ? `data:image/png;base64,${code}` : null;
    }
}
