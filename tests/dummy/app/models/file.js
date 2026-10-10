import Model, { attr } from '@ember-data/model';

/** Stand-in for the console's file model. */
export default class FileModel extends Model {
    @attr('string') url;
    @attr('string') original_filename;
    @attr('string') content_type;
    @attr('string') type;
}
