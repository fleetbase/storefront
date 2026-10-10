import Service, { inject as service } from '@ember/service';
import Evented from '@ember/object/evented';

/**
 * The console injects its router into the engine as `hostRouter`. The dummy app has no
 * host, so this stand-in forwards to its own router service; tests that need control
 * register their own stub over it.
 */
export default class HostRouterService extends Service.extend(Evented) {
    @service router;

    get currentRouteName() {
        return this.router.currentRouteName;
    }

    get currentURL() {
        return this.router.currentURL;
    }

    transitionTo() {
        return this.router.transitionTo(...arguments);
    }

    replaceWith() {
        return this.router.replaceWith(...arguments);
    }

    isActive() {
        return this.router.isActive(...arguments);
    }

    urlFor() {
        return this.router.urlFor(...arguments);
    }

    refresh() {
        return this.router.refresh?.(...arguments);
    }
}
