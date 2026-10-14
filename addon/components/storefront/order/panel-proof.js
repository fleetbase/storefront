import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { isValid, parseISO } from 'date-fns';

/**
 * The 11a proof-of-delivery box: what the driver (or the store) captured for the order, from the
 * overview's `delivery.proof_items`.
 */
export default class StorefrontOrderPanelProofComponent extends Component {
    @service intl;
    @service storefrontOrderActions;

    /** A time on the order's store clock (date-fns pattern), not the browser's. */
    storeTime(value, pattern) {
        return this.storefrontOrderActions.formatForOrder(this.args.order ?? this.order, value, pattern);
    }

    get items() {
        return (this.args.overview?.delivery?.proof_items ?? []).map((proof) => {
            const at = proof.at ? parseISO(proof.at) : null;
            const type = ['photo', 'signature', 'scan'].includes(proof.type) ? proof.type : 'photo';

            return {
                ...proof,
                typeLabel: this.intl.t(`storefront.order.panel.proof-types.${type}`),
                isImage: Boolean(proof.url) && (String(proof.content_type ?? '').startsWith('image/') || !proof.content_type),
                time: at && isValid(at) ? this.storeTime(at, 'd MMM HH:mm') : null,
            };
        });
    }
}
