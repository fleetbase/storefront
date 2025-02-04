import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { EmbeddedRecordsMixin } from '@ember-data/serializer/rest';

export default class FoodTruckSerializer extends ApplicationSerializer.extend(EmbeddedRecordsMixin) {
    /**
     * Embedded relationship attributes
     *
     * @var {Object}
     */
    get attrs() {
        return {
            catalogs: { embedded: 'always' },
            vehicle: { embedded: 'always' },
            serviceArea: { embedded: 'always' },
            zone: { embedded: 'always' },
        };
    }
}
