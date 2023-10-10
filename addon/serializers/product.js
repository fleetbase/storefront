import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { EmbeddedRecordsMixin } from '@ember-data/serializer/rest';

export default class ProductSerializer extends ApplicationSerializer.extend(EmbeddedRecordsMixin) {
    /**
     * Embedded relationship attributes
     *
     * @var {Object}
     */
    get attrs() {
        return {
            variants: { embedded: 'always' },
            addon_categories: { embedded: 'always' },
            primary_image: { embedded: 'always' },
            files: { embedded: 'always' },
            hours: { embedded: 'always' },
            category: { embedded: 'always' },
        };
    }
}
