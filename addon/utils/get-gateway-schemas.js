export default function getGatewaySchemas() {
    const schemas = {
        stripe: {
            secret_key: '',
            publishable_key: '',
            show_postal_code: true,
            ideal_payment: false,
            fpx_payment: false,
        },
        braintree: {
            merchant_id: '',
            public_key: '',
            private_key: '',
            tokenization_key: '',
        },
        qpay: {
            username: '',
            password: '',
            invoice_id: '',
            ebarimt_invoice_id: '',
            district_code: '',
        },
        manual: {
            public_key: '',
            private_key: '',
            key_id: '',
            key_secret: '',
            email: '',
            name: '',
            details: '',
            payment_instructions: '',
        },
    };

    return schemas;
}
