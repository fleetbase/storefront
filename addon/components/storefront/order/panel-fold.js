import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class StorefrontOrderPanelFoldComponent extends Component {
    @tracked isOpen = Boolean(this.args.open);

    @action toggle() {
        this.isOpen = !this.isOpen;
        this.args.onToggle?.(this.isOpen);
    }
}
