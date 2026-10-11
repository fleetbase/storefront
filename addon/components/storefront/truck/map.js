import Component from '@glimmer/component';
import { action, get } from '@ember/object';
import { htmlSafe } from '@ember/template';
import { tracked } from '@glimmer/tracking';

const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

/**
 * The position of a truck's vehicle as `[lat, lng]`, or null when nothing was reported.
 */
export function truckLatLng(truck) {
    const vehicle = truck ? get(truck, 'vehicle') : null;
    const lat = Number((vehicle ? get(vehicle, 'latitude') : null) ?? (truck ? get(truck, 'location.coordinates.1') : null));
    const lng = Number((vehicle ? get(vehicle, 'longitude') : null) ?? (truck ? get(truck, 'location.coordinates.0') : null));

    if (!Number.isFinite(lat) || !Number.isFinite(lng) || (lat === 0 && lng === 0)) {
        return null;
    }

    return [lat, lng];
}

/**
 * Trucks on a Leaflet map: one marker per truck with a position, the popup names the
 * vehicle, and clicking a marker hands the truck to `@onSelect`.
 */
export default class StorefrontTruckMapComponent extends Component {
    @tracked hasLeaflet = true;
    map = null;
    layer = null;

    get style() {
        return htmlSafe(`height: ${this.args.height ?? '320px'};`);
    }

    get trucks() {
        const trucks = this.args.trucks ?? [];

        return trucks?.toArray?.() ?? Array.from(trucks);
    }

    @action setup(element) {
        const L = window.L ?? window.leaflet;

        if (!L) {
            this.hasLeaflet = false;
            return;
        }

        this.map = L.map(element, { zoomControl: true, attributionControl: true });
        L.tileLayer(TILE_URL, { attribution: TILE_ATTRIBUTION, maxZoom: 19 }).addTo(this.map);
        this.layer = L.layerGroup().addTo(this.map);
        this.refresh();
    }

    @action refresh() {
        const L = window.L ?? window.leaflet;

        if (!L || !this.map || !this.layer) {
            return;
        }

        this.layer.clearLayers();

        const points = [];

        this.trucks.forEach((truck) => {
            const latlng = truckLatLng(truck);

            if (!latlng) {
                return;
            }

            points.push(latlng);

            const marker = L.marker(latlng).addTo(this.layer);
            const name = get(truck, 'vehicle.display_name') ?? get(truck, 'vehicle.plate_number') ?? get(truck, 'public_id');

            marker.bindTooltip(String(name ?? ''), { direction: 'top', offset: [-15, -10] });
            marker.on('click', () => this.args.onSelect?.(truck));
        });

        if (points.length === 1) {
            this.map.setView(points[0], 14);
        } else if (points.length > 1) {
            this.map.fitBounds(L.latLngBounds(points), { padding: [24, 24] });
        } else {
            this.map.setView([0, 0], 2);
        }

        // The panel and the page both size the map after insert; redraw once the layout settles.
        setTimeout(() => this.map?.invalidateSize(), 50);
    }

    @action teardown() {
        this.map?.remove();
        this.map = null;
        this.layer = null;
    }
}
