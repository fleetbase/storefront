import Component from '@glimmer/component';
import { get } from '@ember/object';

/**
 * A catalog on the shared card anatomy: name and status in the header, the
 * categories it contains with their product counts in the body, its hours
 * strip, and the status, actions and counts in the footer.
 */
export default class StorefrontCatalogCardComponent extends Component {
    get catalog() {
        return this.args.catalog ?? this.args.resource;
    }

    get categories() {
        const categories = get(this.catalog, 'categories');

        return categories ? categories.slice() : [];
    }

    get categorySummaries() {
        return this.categories.map((category) => ({
            name: get(category, 'name'),
            count: (get(category, 'products') ?? []).length,
        }));
    }

    get productsCount() {
        return this.categorySummaries.reduce((total, category) => total + category.count, 0);
    }

    get hours() {
        const hours = get(this.catalog, 'hours');

        return hours ? hours.slice() : [];
    }

    get subjects() {
        const subjects = get(this.catalog, 'subjects');

        return Array.isArray(subjects) ? subjects : [];
    }

    get status() {
        return get(this.catalog, 'status') ?? 'draft';
    }

    get isDraft() {
        return this.status === 'draft';
    }

    get isEmpty() {
        return this.categories.length === 0;
    }

    /** Row actions passed by the page; `isVisible(catalog)` filters them. */
    get menuItems() {
        const items = this.args.actions ?? [];

        return items.filter((item) => item.separator || typeof item.isVisible !== 'function' || item.isVisible(this.catalog));
    }
}
