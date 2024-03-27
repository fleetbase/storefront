import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';

export default class ProductVariantOptionSerializer extends ApplicationSerializer {
    serialize(snapshot) {
        return {
            uuid: snapshot.record.get('id'),
            product_variant_uuid: snapshot.record.get('product_variant_uuid'),
            name: snapshot.record.get('name'),
            description: snapshot.record.get('description'),
            additional_cost: snapshot.record.get('additional_cost'),
            translations: snapshot.record.get('additional_cost'),
        };
    }
}
