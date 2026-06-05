import Component from '@glimmer/component';

export default class StorefrontOrderDetailsDetailComponent extends Component {
    get orderType() {
        return this.args.resource?.type ?? this.args.resource?.order_config?.name ?? this.args.resource?.order_config?.key;
    }

    get isCashPayment() {
        return Boolean(this.args.resource?.payload?.cod_amount);
    }

    get paymentMethod() {
        if (this.isCashPayment) {
            return 'Cash';
        }

        return this.args.resource?.transaction?.gateway ?? this.args.resource?.meta?.gateway;
    }

    get orderAmount() {
        return this.args.resource?.transaction?.amount ?? this.args.resource?.transaction_amount ?? this.args.resource?.meta?.total ?? 0;
    }

    get orderCurrency() {
        return this.args.resource?.transaction?.currency ?? this.args.resource?.meta?.currency;
    }

    get transactionId() {
        return this.args.resource?.transaction?.public_id ?? this.args.resource?.transaction?.id ?? this.args.resource?.transaction?.uuid ?? this.args.resource?.meta?.transaction_id;
    }

    get checkoutId() {
        return this.args.resource?.meta?.checkout_id;
    }
}
