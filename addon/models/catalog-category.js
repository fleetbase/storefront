import CategoryModel from '@fleetbase/console/models/category';
import { hasMany } from '@ember-data/model';

export default class CatalogCategoryModel extends CategoryModel {
    @hasMany('products', { async: false, inverse: 'catalogCategories' }) products;
}
