import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { EmbeddedRecordsMixin } from '@ember-data/serializer/rest';

export default class NetworkSerializer extends ApplicationSerializer.extend(EmbeddedRecordsMixin) {
    /**
     * Embedded relationship attributes
     *
     * @var {Object}
     */
    get attrs() {
        return {
            stores: { embedded: 'always' },
            gateways: { embedded: 'always' },
            notification_channels: { embedded: 'always' },
            logo: { embedded: 'always' },
            backdrop: { embedded: 'always' },
        };
    }
}
