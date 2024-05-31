import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { inject as service } from '@ember/service';

export default class AddProductAsEntityButtonComponent extends Component {
    @service modalsManager;
    @service fetch;
    @service notifications;
    @tracked order;
    @tracked controller;

    constructor(owner, { order, controller }) {
        super(...arguments);
        this.order = order;
        this.controller = controller;
    }

    @action promptProductSelection() {
        this.modalsManager.show('modals/select-product', {
            title: 'Select Product to Add to Order',
            modalClass: 'modal-lg',
            acceptButtonText: 'Add Products',
            acceptButtonDisabled: true,
            selectedProducts: [],
            selectedStorefront: null,
            confirm: (modal) => {
                modal.startLoading();

                const selectedStorefront = modal.getOption('selectedStorefront');
                const selectedProducts = modal.getOption('selectedProducts', []);
                const products = selectedProducts.map((product) => product.id);

                return this.fetch
                    .post('products/create-entities', { products }, { namespace: 'storefront/int/v1', normalizeToEmberData: true, normalizeModelType: 'entity' })
                    .then((entities) => {
                        this.controller.addEntities(entities);
                        if (isArray(this.order.meta)) {
                            this.order.meta.pushObjects([
                                {
                                    key: 'storefront',
                                    value: selectedStorefront.name
                                },
                                {
                                    key: 'storefront_id',
                                    value: selectedStorefront.public_id
                                }
                            ]);
                        }
                    })
                    .catch((error) => {
                        this.notifications.serverError(error);
                    });
            },
        });
    }
}
