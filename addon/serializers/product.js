import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { EmbeddedRecordsMixin } from '@ember-data/serializer/rest';
import { isArray } from '@ember/array';

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

    serializeHasMany(snapshot, json, relationship) {
        let key = relationship.key;

        if (key === 'addon_categories') {
            const addonCategories = snapshot.record.get('addon_categories');

            if (isArray(addonCategories)) {
                json.addon_categories = addonCategories;
            }
            return;
        }

        return super.serializeHasMany(...arguments);
    }
}
