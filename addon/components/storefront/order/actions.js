import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

export default class StorefrontOrderActionsComponent extends Component {
    @service storefrontOrderActions;

    get items() {
        return this.storefrontOrderActions.actionItemsFor(this.args.order, this.args.onChange).filter((item) => !item.separator);
    }

    get buttonSize() {
        return this.args.size ?? 'xs';
    }

    get showText() {
        return this.args.showText === true;
    }
}
