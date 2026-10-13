import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { underscore } from '@ember/string';
import { format, isValid, parseISO } from 'date-fns';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';

const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

// Fleet-Ops' order route colours by status and its waypoint markers (P pickup, D dropoff), so
// the route reads the same here as on the Fleet-Ops order page.
const STATUS_COLORS = { created: '#2563EB', dispatched: '#4F46E5', started: '#0891B2', enroute: '#CA8A04', driver_enroute: '#CA8A04', completed: '#16A34A', canceled: '#DC2626' };
const ROLE_MARKERS = { pickup: { label: 'P', color: '#22C55E' }, dropoff: { label: 'D', color: '#EF4444' } };

function waypointIconHtml(label, color) {
    return `<div class="sfop-waypoint" style="background:${color}">${label}</div>`;
}

function coordinates(place) {
    const lat = Number(place?.latitude ?? place?.location?.coordinates?.[1]);
    const lng = Number(place?.longitude ?? place?.location?.coordinates?.[0]);

    return Number.isFinite(lat) && Number.isFinite(lng) && (lat || lng) ? [lat, lng] : null;
}

/**
 * The 11a delivery card. The route is drawn by the router Fleet-Ops registers for the company
 * (OSRM by default); without it the stops are joined by a straight line.
 */
export default class StorefrontOrderPanelDeliveryComponent extends Component {
    @service intl;
    @service universe;
    @service currentUser;
    @service storefrontOrderActions;
    @tracked expanded = false;
    map = null;
    // What "focus the route" fits the map to: the routed path once known, else the stops.
    routePoints = [];

    get order() {
        return this.args.order;
    }

    get isPickup() {
        return Boolean(this.order?.meta?.is_pickup);
    }

    get pickup() {
        return this.order?.payload?.pickup;
    }

    get dropoff() {
        return this.order?.payload?.dropoff;
    }

    /** A place's full address, one line per part: street, building, area, city and postcode, country. */
    addressLines(place, { withName = false } = {}) {
        if (!place) {
            return [];
        }

        const street = [place.street1, place.street2].filter(Boolean).join(', ');
        const area = [place.building, place.neighborhood, place.district].filter(Boolean).join(', ');
        const city = [place.city, place.province, place.postal_code].filter(Boolean).join(', ');
        const lines = [withName && place.name && place.name !== place.street1 ? place.name : null, street, area, city, place.country_name ?? place.country].filter(Boolean);

        return lines.length ? lines : [place.address].filter(Boolean);
    }

    get pickupLines() {
        return this.addressLines(this.pickup);
    }

    get dropoffLines() {
        return this.addressLines(this.dropoff, { withName: true });
    }

    get summary() {
        const parts = [];
        const meters = this.args.overview?.delivery?.distance_m ?? this.order?.distance;
        if (meters) {
            parts.push(meters >= 1000 ? `${(meters / 1000).toFixed(1)} km` : `${Math.round(meters)} m`);
        }

        const due = this.args.overview?.due_at ? parseISO(this.args.overview.due_at) : null;
        if (due && isValid(due) && !this.args.overview?.delivery?.delivered_at) {
            parts.push(this.intl.t('storefront.order.panel.eta', { time: format(due, 'HH:mm') }));
        }

        return parts.join(' · ');
    }

    get instructions() {
        const meta = this.dropoff?.meta ?? {};
        return meta.instructions ?? meta.delivery_instructions ?? null;
    }

    /** A recipient set for this order only (edit with "this order only"). */
    get contactLine() {
        const contact = this.order?.meta?.contact;
        return contact ? [contact.name, contact.phone].filter(Boolean).join(' · ') : null;
    }

    @action editAddress() {
        return this.storefrontOrderActions.editOrder(this.order, { focus: 'dropoff' });
    }

    /** The router Fleet-Ops registered (the company's routing setting, else OSRM), if any. */
    get router() {
        try {
            const registry = this.universe.getApplicationInstance()?.resolveRegistration('registry:routing-controls');
            const preferred = underscore(this.currentUser.getOption?.('routing', { router: 'osrm' })?.router ?? 'osrm');
            let control = registry?.routers?.[preferred] ?? registry?.routers?.osrm;
            control = typeof control === 'function' ? control() : control;

            return control?.router ?? null;
        } catch {
            return null;
        }
    }

    @action drawMap(element) {
        const L = window.L;
        if (!L || !element) {
            return;
        }

        this.removeMap();
        const stops = [
            ['pickup', coordinates(this.pickup)],
            ['dropoff', coordinates(this.dropoff)],
        ].filter(([, latLng]) => latLng);
        if (!stops.length) {
            return;
        }

        // The map can be moved and zoomed (double-click, pinch); "focus the route" brings it back.
        // The wheel stays with the panel so scrolling the order does not zoom the map.
        const map = L.map(element, {
            zoomControl: false,
            attributionControl: false,
            scrollWheelZoom: false,
            boxZoom: false,
            keyboard: false,
        });
        L.tileLayer(TILE_URL, { maxZoom: 19 }).addTo(map);
        stops.forEach(([role, latLng]) => {
            const marker = ROLE_MARKERS[role];
            L.marker(latLng, {
                icon: L.divIcon({ className: 'sfop-waypoint-marker', html: waypointIconHtml(marker.label, marker.color), iconSize: [26, 26], iconAnchor: [13, 13] }),
                zIndexOffset: role === 'pickup' ? 2200 : 2100,
            }).addTo(map);
        });

        const driverAt = coordinates(this.order?.driver_assigned);
        if (driverAt) {
            L.marker(driverAt, { icon: L.icon({ iconUrl: this.order.driver_assigned.vehicle_avatar, iconSize: [22, 22], iconAnchor: [11, 11] }) }).addTo(map);
        }

        const latLngs = stops.map(([, latLng]) => latLng);
        this.map = map;
        this.routePoints = latLngs;
        this.focusRoute();

        if (latLngs.length > 1) {
            this.drawRoute(map, latLngs);
        }
    }

    drawRoute(map, latLngs) {
        const L = window.L;
        const color = STATUS_COLORS[this.order?.status] ?? STATUS_COLORS.created;
        const line = (points, options = {}) => {
            if (this.map !== map) {
                return;
            }
            L.polyline(points, { color: '#1e293b', weight: 6, opacity: 0.35, ...options.casing }).addTo(map);
            L.polyline(points, { color, weight: 4, opacity: 0.9, ...options.line }).addTo(map);
        };

        const done = (path) => {
            path?.length ? line(path) : line(latLngs, { line: { dashArray: '6 6' } });
            if (path?.length && this.map === map) {
                this.routePoints = path;
                this.focusRoute();
            }
        };

        // The router Fleet-Ops registered (the company's routing setting) when Fleet-Ops is loaded,
        // else the same OSRM host Fleet-Ops' default router uses.
        const router = this.router;
        if (router?.route) {
            try {
                router.route(
                    latLngs.map((latLng) => ({ latLng: L.latLng(latLng[0], latLng[1]) })),
                    (error, routes) => done(!error && routes?.[0]?.coordinates?.length ? routes[0].coordinates : null)
                );
                return;
            } catch {
                // fall through to OSRM
            }
        }

        const points = latLngs.map(([lat, lng]) => `${lng},${lat}`).join(';');
        fetch(`${getRoutingHost(this.order?.payload)}/route/v1/driving/${points}?overview=full&geometries=geojson`)
            .then((response) => (response.ok ? response.json() : null))
            .then((json) => done((json?.routes?.[0]?.geometry?.coordinates ?? []).map(([lng, lat]) => [lat, lng])))
            .catch(() => done(null));
    }

    /** Fit the map around the route (or the stops before it is routed). */
    @action focusRoute() {
        const map = this.map;
        const points = this.routePoints;
        if (!map || !points?.length) {
            return;
        }

        points.length > 1 ? map.fitBounds(window.L.latLngBounds(points), { padding: [22, 22] }) : map.setView(points[0], 15);
    }

    /** Widen the map to the whole card, the addresses moving below it, or put it back. */
    @action toggleExpanded() {
        this.expanded = !this.expanded;
        requestAnimationFrame(() => {
            this.map?.invalidateSize();
            this.focusRoute();
        });
    }

    @action removeMap() {
        this.map?.remove();
        this.map = null;
        this.routePoints = [];
    }
}
