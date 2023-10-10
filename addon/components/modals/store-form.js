import Component from '@glimmer/component';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class ModalsStoreFormComponent extends Component {
    get activeStore() {
        return this.args.options.store;
    }

    @action addTag(tag) {
        if (!isArray(this.activeStore.tags)) {
            this.activeStore.tags = [];
        }

        this.activeStore.tags?.pushObject(tag);
    }

    @action removeTag(index) {
        this.activeStore.tags?.removeAt(index);
    }
}
