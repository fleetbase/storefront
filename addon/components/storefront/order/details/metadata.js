import Component from '@glimmer/component';
import isEmptyObject from '@fleetbase/ember-core/utils/is-empty-object';

export default class StorefrontOrderDetailsMetadataComponent extends Component {
    get emptyMetadata() {
        return isEmptyObject(this.args.resource.meta);
    }
}
