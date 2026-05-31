import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class StorefrontOrderDetailsActivityComponent extends Component {
    @service appCache;
    @service notifications;
    @tracked layout = this.appCache.get('storefront:order:activity:layout', 'timeline');

    constructor() {
        super(...arguments);
        this.loadActivity.perform();
    }

    get activity() {
        const activity = this.args.resource?.tracking_statuses ?? [];
        const trackingNumberUuid = this.args.resource?.tracking_number_uuid;

        if (!trackingNumberUuid || typeof activity.filter !== 'function') {
            return activity;
        }

        return activity.filter((item) => item.tracking_number_uuid === trackingNumberUuid);
    }

    /* eslint-disable ember/no-side-effects */
    get actionButtons() {
        return [
            {
                items: [
                    {
                        text: 'Reload activity',
                        icon: 'refresh',
                        onClick: () => {
                            this.loadActivity.perform();
                        },
                    },
                    {
                        text: this.layout === 'timeline' ? 'View activity as list' : 'View activity as timeline',
                        icon: this.layout === 'timeline' ? 'list' : 'timeline',
                        onClick: () => {
                            this.layout = this.layout === 'timeline' ? 'list' : 'timeline';
                            this.appCache.set('storefront:order:activity:layout', this.layout);
                        },
                    },
                ],
            },
        ];
    }
    /* eslint-enable ember/no-side-effects */

    @task *loadActivity() {
        const order = this.args.resource;

        if (!order || typeof order.loadTrackingActivity !== 'function') {
            return;
        }

        try {
            yield order.loadTrackingActivity();
            if (typeof this.args.onChange === 'function') {
                this.args.onChange(order);
            }
        } catch (err) {
            this.notifications.serverError(err);
        }
    }
}
