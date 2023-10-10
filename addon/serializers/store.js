import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { EmbeddedRecordsMixin } from '@ember-data/serializer/rest';

export default class StoreSerializer extends ApplicationSerializer.extend(EmbeddedRecordsMixin) {
    /**
     * Embedded relationship attributes
     *
     * @var {Object}
     */
    get attrs() {
        return {
            gateways: { embedded: 'always' },
            notification_channels: { embedded: 'always' },
            category: { embedded: 'always' },
            logo: { embedded: 'always' },
            backdrop: { embedded: 'always' },
            files: { embedded: 'always' },
        };
    }
}
