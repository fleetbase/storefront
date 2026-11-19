import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const P = 'storefront.settings.push-app';
const blankForm = (name = '') => ({
    name,
    status: 'active',
    apn: { key_id: '', team_id: '', app_bundle_id: '', private_key_content: '', environment: '' },
    fcm: { credentials: '', android_channel_id: '' },
    apple_sign_in: { key_id: '', team_id: '', app_bundle_id: '', private_key_content: '' },
});

/**
 * The push app of a store or network: one of core-api's push apps, owned by it, with its
 * Apple (APNs) and Firebase (FCM) settings and an optional Sign in with Apple key. The server
 * (`storefront/int/v1/push-apps/{owner}`) checks every setting and answers what to fix.
 * Secrets are never shown again; leaving one empty when editing keeps the saved one.
 *
 * Args: @owner (a store or network record).
 */
export default class StorefrontPushAppSettingsComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;
    @service abilities;

    @tracked pushApp = null;
    @tracked loaded = false;
    @tracked editing = false;
    @tracked form = blankForm();
    @tracked error = null;
    @tracked testing = false;
    @tracked test = { token: '', push_provider: '', environment: '' };
    @tracked testResult = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get kind() {
        return this.args.owner?.constructor?.modelName === 'network' ? 'network' : 'store';
    }

    get endpoint() {
        return `push-apps/${this.args.owner?.id}`;
    }

    get canUpdate() {
        return this.abilities.can('storefront update notification-channel');
    }

    get environments() {
        return [
            { value: '', label: this.intl.t(`${P}.environment-both`) },
            { value: 'production', label: this.intl.t(`${P}.environment-production`) },
            { value: 'sandbox', label: this.intl.t(`${P}.environment-sandbox`) },
        ];
    }

    get tokenTypes() {
        return [
            { value: '', label: this.intl.t(`${P}.token-type-auto`) },
            { value: 'apn', label: this.intl.t(`${P}.token-type-apn`) },
            { value: 'fcm', label: this.intl.t(`${P}.token-type-fcm`) },
        ];
    }

    /** Another store or network was opened in the same place. */
    @action reload() {
        this.pushApp = null;
        this.loaded = false;
        this.editing = false;
        this.testing = false;
        this.load.perform();
    }

    @task *load() {
        if (!this.args.owner?.id) return;

        try {
            const { push_app } = yield this.fetch.get(this.endpoint, {}, { namespace: 'storefront/int/v1' });
            this.pushApp = push_app;
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.loaded = true;
        }
    }

    @action edit() {
        this.error = null;
        this.testing = false;
        const app = this.pushApp;
        if (!app) {
            this.form = blankForm(this.args.owner?.name ?? '');
            this.editing = true;
            return;
        }

        const { apn, fcm } = app.providers ?? {};
        const signIn = app.apple_sign_in;
        this.form = {
            name: app.name,
            status: app.status,
            apn: { key_id: apn?.key_id ?? '', team_id: apn?.team_id ?? '', app_bundle_id: apn?.app_bundle_id ?? '', private_key_content: '', environment: apn?.environment ?? '' },
            fcm: { credentials: '', android_channel_id: fcm?.android_channel_id ?? '' },
            apple_sign_in: { key_id: signIn?.key_id ?? '', team_id: signIn?.team_id ?? '', app_bundle_id: signIn?.app_bundle_id ?? '', private_key_content: '' },
        };
        this.editing = true;
    }

    @action cancel() {
        this.editing = false;
        this.error = null;
    }

    @action set(path, event) {
        const value = event?.target ? event.target.value : event;
        const [group, field] = path.split('.');
        this.form = field ? { ...this.form, [group]: { ...this.form[group], [field]: value } } : { ...this.form, [group]: value };
    }

    @action toggleStatus(on) {
        this.form = { ...this.form, status: on ? 'active' : 'inactive' };
    }

    @task *save() {
        this.error = null;
        const isNew = !this.pushApp;
        try {
            const { push_app } = yield this.fetch.put(this.endpoint, this.form, { namespace: 'storefront/int/v1' });
            this.pushApp = push_app;
            this.editing = false;
            this.notifications.success(this.intl.t(isNew ? `${P}.created` : `${P}.saved`, { name: push_app.name }));
        } catch (error) {
            this.error = error?.message ?? String(error);
        }
    }

    @action remove() {
        const app = this.pushApp;
        this.modalsManager.confirm({
            title: this.intl.t(`${P}.delete-title`, { name: app.name }),
            body: this.intl.t(`${P}.delete-body`, { count: app.devices ?? 0 }),
            acceptButtonText: this.intl.t(`${P}.delete`),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.delete(this.endpoint, {}, { namespace: 'storefront/int/v1' });
                    this.pushApp = null;
                    this.notifications.success(this.intl.t(`${P}.deleted`, { name: app.name }));
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action toggleTest() {
        this.testing = !this.testing;
        this.testResult = null;
        this.test = { token: '', push_provider: '', environment: '' };
    }

    @action setTest(field, event) {
        const value = event?.target ? event.target.value : event;
        this.test = { ...this.test, [field]: value };
        if (this.test.push_provider === 'fcm') {
            this.test = { ...this.test, environment: '' };
        }
    }

    @task *sendTest() {
        this.testResult = null;
        try {
            const result = yield this.fetch.post(`${this.endpoint}/test`, this.test, { namespace: 'storefront/int/v1' });
            this.testResult = result.sent
                ? { ok: true, text: this.intl.t(`${P}.test-sent`) }
                : { ok: false, text: this.intl.t(`${P}.test-failed`, { reason: result.reason ?? result.status }) };
        } catch (error) {
            this.testResult = { ok: false, text: error?.message ?? String(error) };
        }
    }
}
