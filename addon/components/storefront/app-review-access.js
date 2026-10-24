import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * App Review access for a store or network: lets Apple and Google reviewers sign in to its app
 * with a fixed test number (never texted) and a code the owner gives the app store. The code
 * is stored hashed on the server, so it is shown only in the response that created it.
 *
 * Args: @owner (a store or network record).
 */
export default class StorefrontAppReviewAccessComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;

    @tracked status = null;
    @tracked code = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get type() {
        return this.args.owner?.constructor?.modelName === 'network' ? 'network' : 'store';
    }

    get endpoint() {
        return `${this.type}s/${this.args.owner?.id}/app-review`;
    }

    get permission() {
        return `storefront manage-app-review ${this.type}`;
    }

    get enabled() {
        return this.status?.enabled === true;
    }

    get codeCreatedAt() {
        const at = this.status?.code_generated_at;
        return at ? new Date(at).toLocaleString() : null;
    }

    /** Another store or network was opened in the same place: forget the last one's code. */
    @action reload() {
        this.status = null;
        this.code = null;
        this.load.perform();
    }

    @task *load() {
        if (!this.args.owner?.id) return;

        try {
            const { app_review } = yield this.fetch.get(this.endpoint, {}, { namespace: 'storefront/int/v1' });
            this.status = app_review;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *save(payload, message) {
        try {
            const { app_review } = yield this.fetch.put(this.endpoint, payload, { namespace: 'storefront/int/v1' });
            const { code, ...status } = app_review;
            this.status = status;
            if (code) this.code = code;
            if (!status.enabled) this.code = null;
            this.notifications.success(message);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action turnOn() {
        this.save.perform({ enabled: true }, this.intl.t('storefront.settings.app-review.turned-on'));
    }

    @action turnOff() {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.settings.app-review.turn-off-title'),
            body: this.intl.t('storefront.settings.app-review.turn-off-body', { name: this.args.owner?.name }),
            acceptButtonText: this.intl.t('storefront.settings.app-review.turn-off'),
            acceptButtonType: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                await this.save.perform({ enabled: false }, this.intl.t('storefront.settings.app-review.turned-off'));
                modal.done();
            },
        });
    }

    @action regenerate() {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.settings.app-review.regenerate-title'),
            body: this.intl.t('storefront.settings.app-review.regenerate-body'),
            acceptButtonText: this.intl.t('storefront.settings.app-review.regenerate'),
            acceptButtonType: 'danger',
            acceptButtonIcon: 'rotate',
            confirm: async (modal) => {
                modal.startLoading();
                await this.save.perform({ regenerate_code: true }, this.intl.t('storefront.settings.app-review.regenerated'));
                modal.done();
            },
        });
    }
}
