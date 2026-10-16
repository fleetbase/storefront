import CategoryModel from '@fleetbase/console/models/category';
import { attr, hasMany } from '@ember-data/model';

export default class CatalogCategoryModel extends CategoryModel {
    @hasMany('products', { async: false, inverse: 'catalogCategories' }) products;
    /** Per-catalog overrides keyed by product uuid: `{ price, is_available }`, each null to inherit from the store. */
    @attr('raw') product_overrides;
    /** A new category's key until it is saved, and the key of a new parent it sits under: the catalog save places it by these. */
    @attr('string') key;
    @attr('string') parent_key;
}
