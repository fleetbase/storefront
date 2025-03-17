import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { EmbeddedRecordsMixin } from '@ember-data/serializer/rest';
import { isArray } from '@ember/array';

export default class ProductVariantSerializer extends ApplicationSerializer.extend(EmbeddedRecordsMixin) {
    /**
     * Embedded relationship attributes
     *
     * @var {Object}
     */
    get attrs() {
        return {
            options: { embedded: 'always' },
        };
    }

    serialize(snapshot) {
        const options = snapshot.record.get('options');
        return {
            uuid: snapshot.record.get('id'),
            product_uuid: snapshot.record.get('product_uuid'),
            name: snapshot.record.get('name'),
            description: snapshot.record.get('description'),
            is_multiselect: snapshot.record.get('is_multiselect'),
            is_required: snapshot.record.get('is_required'),
            translations: snapshot.record.get('translations'),
            options: isArray(options) ? Array.from(options) : [],
        };
    }

    serializeHasMany(snapshot, json, relationship) {
        let key = relationship.key;

        if (key === 'options') {
            const options = snapshot.record.get('options');
            json.options = isArray(options) ? Array.from(options) : [];
        }

        return super.serializeHasMany(...arguments);
    }
}
