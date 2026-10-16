import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { parentCategoryOptions } from '../../../utils/category-tree';

/**
 * A product category's name, description and icon, and, given the store's other categories,
 * the category it sits under (up to three levels deep).
 */
export default class StorefrontProductCategoryFormComponent extends Component {
    @service intl;

    get hasParentPicker() {
        return Array.isArray(this.args.categories) || typeof this.args.categories?.toArray === 'function';
    }

    get parentOptions() {
        const indent = '    ';

        return [
            { value: '', label: this.intl.t('storefront.component.modals.create-product-category.top-level') },
            ...parentCategoryOptions(this.args.categories, this.args.category).map((row) => ({
                value: row.category.id,
                label: `${indent.repeat(row.depth)}${row.path}`,
            })),
        ];
    }

    /** Sets the parent and its id together: the serializer writes the parent's id when one is set. */
    @action selectParent(value) {
        const parent = parentCategoryOptions(this.args.categories, this.args.category).find((row) => row.category.id === value)?.category ?? null;

        this.args.category.setProperties({ parent_uuid: parent ? value : null, parent });
        this.args.onChange?.();
    }
}
