import CategoryModel from '@fleetbase/console/models/category';
import { hasMany, belongsTo } from '@ember-data/model';

export default class CatalogCategoryModel extends CategoryModel {
    @belongsTo('catalog') catalog;
    @hasMany('products') products;
}
