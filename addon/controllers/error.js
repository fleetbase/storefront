import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

/**
 * Backs the engine's `error` substate. The model is whatever the failed route's model
 * hook rejected with: an Error, an API error payload (`errors[]`) or a string.
 */
export default class ErrorController extends Controller {
    @service hostRouter;

    get message() {
        const error = this.model;

        if (!error) {
            return null;
        }

        if (typeof error === 'string') {
            return error;
        }

        const apiError = Array.isArray(error.errors) ? error.errors[0] : null;
        const message = apiError?.detail ?? apiError?.message ?? apiError?.title ?? error.message;

        return typeof message === 'string' && message.trim() ? message : null;
    }

    /** The transition whose model hook rejected, set by the application route's `error` action. */
    @tracked failedTransition = null;

    /**
     * Re-attempts the page the user was heading to. An aborted transition leaves the URL
     * on the previous page, so the failed transition itself is retried when it is known.
     */
    @action retry() {
        const transition = this.failedTransition;

        if (transition && typeof transition.retry === 'function') {
            return transition.retry();
        }

        const url = this.hostRouter.currentURL;

        if (!url) {
            return window.location.reload();
        }

        return this.hostRouter.transitionTo(url);
    }

    @action goToDashboard() {
        return this.hostRouter.transitionTo('console.storefront');
    }
}
