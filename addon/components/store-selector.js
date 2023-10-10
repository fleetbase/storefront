import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class StoreSelectorComponent extends Component {
    @action onSwitchStore(store) {
        const { onSwitchStore } = this.args;

        if (typeof onSwitchStore === 'function') {
            onSwitchStore(store);
        }
    }

    @action onCreateStore() {
        const { onCreateStore } = this.args;

        if (typeof onCreateStore === 'function') {
            onCreateStore();
        }
    }
}
