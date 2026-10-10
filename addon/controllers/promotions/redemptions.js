import BaseController from '@fleetbase/storefront-engine/controllers/base-controller';
import { tracked, cached } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { task, timeout } from 'ember-concurrency';

/**
 * Every time a promotion was used: the data the API already records, with no screen until now.
 */
export default class PromotionsRedemptionsController extends BaseController {
    @service intl;
    @service storefrontOrderActions;
    @service store;
    queryParams = this.registeredQueryParams('promotion-redemption', ['query', 'promotion', 'status', 'page']);
    @tracked query;
    @tracked promotion;
    @tracked status;
    @tracked page = 1;

    get columns() {
        return [
            { id: 'promotion', label: this.intl.t('storefront.promotions.list.tab-title'), valuePath: 'promotion_name', resizable: true, width: '220px' },
            { id: 'code', label: this.intl.t('storefront.promotions.codes.code'), valuePath: 'code', cellComponent: 'click-to-copy', width: '140px' },
            { id: 'customer', label: this.intl.t('storefront.common.customer'), valuePath: 'customer_name', width: '180px' },
            { id: 'order', label: this.intl.t('storefront.common.orders'), valuePath: 'order_public_id', cellComponent: 'table/cell/anchor', action: this.viewOrder, width: '170px' },
            {
                id: 'amount',
                label: this.intl.t('storefront.promotions.redemptions.discount'),
                valuePath: 'amount',
                cellComponent: 'table/cell/currency',
                currencyPath: 'currency',
                width: '120px',
            },
            { id: 'status', label: this.intl.t('storefront.common.status'), valuePath: 'status', cellComponent: 'table/cell/status', width: '120px' },
            { id: 'redeemed-at', label: this.intl.t('storefront.promotions.redemptions.redeemed-at'), valuePath: 'redeemedAt', width: '170px' },
        ];
    }

    @task({ restartable: true }) *search({ target: { value } }) {
        if (isBlank(value)) {
            this.query = null;
            return;
        }

        yield timeout(250);
        this.page = 1;
        this.query = value;
    }

    @action resetFilters() {
        this.query = null;
        this.promotion = null;
        this.status = null;
        this.page = 1;
    }

    @action async viewOrder(redemption) {
        if (!redemption?.order_uuid) {
            return;
        }

        return this.storefrontOrderActions.viewOrder({ id: redemption.order_uuid, public_id: redemption.order_public_id });
    }

    @cached get registeredColumns() {
        return this.mergeRegisteredColumns('promotion-redemption', this.columns);
    }
}
