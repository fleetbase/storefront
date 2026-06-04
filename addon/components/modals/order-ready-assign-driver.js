import Component from '@glimmer/component';
import { action, set } from '@ember/object';
import { inject as service } from '@ember/service';

export default class ModalsOrderReadyAssignDriverComponent extends Component {
    @service intl;
    @service modalsManager;

    get dispatchButtonText() {
        return this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-accept-button-text');
    }

    get assignAndDispatchButtonText() {
        return this.intl.t('storefront.component.widget.orders.mark-as-ready-modal-not-adhoc-accept-button-text');
    }

    @action toggleAdhoc(isAdhoc) {
        set(this.args.options, 'adhoc', isAdhoc);
        this.modalsManager.setOption('acceptButtonText', isAdhoc ? this.dispatchButtonText : this.assignAndDispatchButtonText);
    }
}
