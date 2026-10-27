import Component from '@glimmer/component';

/**
 * Marks an order an app store reviewer placed (`meta.app_review`, set at checkout when the
 * customer is the App Review account) so the store does not fulfil it.
 */
export default class StorefrontOrderPanelAppReviewComponent extends Component {
    get isAppReview() {
        return this.args.order?.meta?.app_review === true;
    }
}
