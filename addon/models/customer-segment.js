import Model, { attr } from '@ember-data/model';

export default class CustomerSegmentModel extends Model {
    /** @ids */
    @attr('string') public_id;
    @attr('string') owner_uuid;
    @attr('string') owner_type;

    /** @attributes */
    @attr('string') name;
    @attr('string') description;
    @attr('raw') rules;
    @attr('raw') meta;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @computed */
    get ruleCount() {
        return Object.values(this.rules ?? {}).filter((value) => value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && value.length === 0)).length;
    }
}
