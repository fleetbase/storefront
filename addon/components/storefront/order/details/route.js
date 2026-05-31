import Component from '@glimmer/component';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class StorefrontOrderDetailsRouteComponent extends Component {
    constructor() {
        super(...arguments);
        this.loadTrackerData.perform();
    }

    get trackerData() {
        return this.args.resource?.tracker_data;
    }

    get showRouteEtaData() {
        if (this.trackerData?.lifecycle?.show_live_eta !== undefined) {
            return Boolean(this.trackerData.lifecycle.show_live_eta);
        }

        const status = String(this.args.resource?.status ?? 'created').toLowerCase();
        const hasStarted = Boolean(this.args.resource?.started ?? this.args.resource?.started_at ?? status === 'started');

        return hasStarted && !['completed', 'canceled'].includes(status);
    }

    get hasTrackingRouteSummary() {
        return Boolean(this.trackerData?.route || this.trackerData?.eta);
    }

    get hasTrackingDistance() {
        return this.trackerData?.route?.distance_m !== null && this.trackerData?.route?.distance_m !== undefined;
    }

    get hasCompletionEta() {
        return this.showRouteEtaData && Boolean(this.trackerData?.eta?.completion_at);
    }

    get routeStopsCount() {
        const payload = this.args.resource?.payload;
        const waypoints = payload?.waypoints;
        const waypointCount = typeof waypoints?.toArray === 'function' ? waypoints.toArray().length : Array.isArray(waypoints) ? waypoints.length : (waypoints?.length ?? 0);

        return [payload?.pickup, payload?.dropoff].filter(Boolean).length + waypointCount;
    }

    get trackingDurationSeconds() {
        if (!this.showRouteEtaData) {
            return null;
        }

        return this.trackerData?.route?.duration_in_traffic_s ?? this.trackerData?.route?.duration_s;
    }

    get hasRouteSummaryLine() {
        return this.hasTrackingRouteSummary || this.routeStopsCount > 0;
    }

    @task *loadTrackerData() {
        if (!this.args.resource || this.args.resource.tracker_data || typeof this.args.resource.loadTrackerData !== 'function') {
            return;
        }

        try {
            yield this.args.resource.loadTrackerData();
        } catch (err) {
            debug('Failed to load order tracker data for route: ' + err.message);
        }
    }
}
