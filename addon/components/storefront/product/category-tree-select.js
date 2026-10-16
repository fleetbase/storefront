import Component from '@glimmer/component';
import { action } from '@ember/object';
import { flattenCategoryTree } from '../../../utils/category-tree';

/**
 * Picks a category from a store's tree, each option indented and named by its path
 * ("Drinks › Coffee › Cold brew").
 */
export default class StorefrontProductCategoryTreeSelectComponent extends Component {
    get rows() {
        return flattenCategoryTree(this.args.categories);
    }

    get options() {
        const indent = '    ';

        return this.rows.map((row) => ({ value: row.category.id, label: `${indent.repeat(row.depth)}${row.path}` }));
    }

    @action select(value) {
        const row = this.rows.find((candidate) => candidate.category.id === value);
        this.args.onSelect?.(row?.category ?? null);
    }
}
