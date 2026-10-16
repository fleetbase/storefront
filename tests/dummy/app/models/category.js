import Model, { attr } from '@ember-data/model';

/** Stand-in for the console's category model, which the engine's models relate to. */
export default class CategoryModel extends Model {
    @attr('string') name;
    @attr('string') slug;
    @attr('string') description;
    @attr('string') icon;
    @attr('string') icon_color;
    @attr('string') icon_file_uuid;
    @attr('string') icon_url;
    @attr('string') company_uuid;
    @attr('string') owner_uuid;
    @attr('string') owner_type;
    @attr('string') parent_uuid;
    @attr('string') for;
}
