import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { EmbeddedRecordsMixin } from '@ember-data/serializer/rest';
import { isArray } from '@ember/array';

export function filterHasManyForNewRecords(model, relations = []) {
    for (let i = 0; i < relations.length; i++) {
        let relationKey = relations[i];
        let data = model[relationKey];
        if (isArray(data)) {
            data = data.filter((_) => {
                if (relationKey === 'variants') {
                    _ = filterHasManyForNewRecords(_, ['options']);
                }
                return !_.isNew;
            });
        } else {
            data = [];
        }

        model.set(relationKey, data);
    }

    return model;
}

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
            json.addon_categories = isArray(addonCategories) ? Array.from(addonCategories) : [];
        }

        if (key === 'variants') {
            const variants = snapshot.record.get('variants');
            json.variants = isArray(variants) ? Array.from(variants) : [];
        }

        return super.serializeHasMany(...arguments);
    }
}
