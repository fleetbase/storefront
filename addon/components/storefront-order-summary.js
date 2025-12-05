import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import getTipAmount from '../helpers/get-tip-amount';

export default class StorefrontOrderSummaryComponent extends Component {
    @service('universe/registry-service') registryService;

    constructor() {
        super(...arguments);
        this.registryService.registerHelper('get-tip-amount', getTipAmount);
    }
}
