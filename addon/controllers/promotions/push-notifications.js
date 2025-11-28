import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class PromotionsPushNotificationsController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service currentUser;
    @service storefront;

    @tracked title = '';
    @tracked body = '';
    @tracked selectedCustomers = [];
    @tracked selectAllCustomers = false;
    @tracked isLoading = false;

    @action
    async sendPushNotification(event) {
        event.preventDefault();

        // Validate form
        if (!this.title || !this.body) {
            this.notifications.warning(this.intl.t('storefront.promotions.push-notifications.validation-title-body-required'));
            return;
        }

        if (!this.selectAllCustomers && (!this.selectedCustomers || this.selectedCustomers.length === 0)) {
            this.notifications.warning(this.intl.t('storefront.promotions.push-notifications.validation-customers-required'));
            return;
        }

        this.isLoading = true;

        try {
            const payload = {
                title: this.title,
                body: this.body,
                store: this.storefront.getActiveStore('public_id'),
                select_all: this.selectAllCustomers,
            };

            // Only include customer IDs if not selecting all
            if (!this.selectAllCustomers) {
                payload.customers = this.selectedCustomers.map((customer) => customer.id);
            }

            await this.fetch.post('storefront/int/v1/actions/send-push-notification', payload);

            this.notifications.success(this.intl.t('storefront.promotions.push-notifications.notification-sent-success'));

            // Reset form
            this.title = '';
            this.body = '';
            this.selectedCustomers = [];
            this.selectAllCustomers = false;
        } catch (error) {
            this.notifications.error(this.intl.t('storefront.promotions.push-notifications.notification-sent-error'));
        } finally {
            this.isLoading = false;
        }
    }
}
