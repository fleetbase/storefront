import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, get } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * The category sidebar: nested categories with product counts, plus the smart groups a
 * store acts on (out of stock, drafts, recommended, on sale, uncategorised).
 */
export default class StorefrontProductCategorySidebarComponent extends Component {
    @service intl;
    @tracked collapsed = new Set();

    get categories() {
        return this.args.categories?.toArray?.() ?? Array.from(this.args.categories ?? []);
    }

    get counts() {
        return this.args.counts ?? {};
    }

    get total() {
        return this.args.summary?.total ?? null;
    }

    countFor(category) {
        const own = this.counts[category.id] ?? 0;
        const children = this.childrenOf(category);

        return children.reduce((sum, child) => sum + this.countFor(child), own);
    }

    childrenOf(parent) {
        return this.categories.filter((category) => get(category, 'parent_uuid') === parent.id);
    }

    /**
     * Categories as a flat list with depth, parents first, children indented under them.
     */
    get rows() {
        const ids = new Set(this.categories.map((category) => category.id));
        const roots = this.categories.filter((category) => !get(category, 'parent_uuid') || !ids.has(get(category, 'parent_uuid')));
        const rows = [];
        const walk = (category, depth) => {
            const children = this.childrenOf(category);
            rows.push({
                category,
                depth,
                count: this.countFor(category),
                hasChildren: children.length > 0,
                isCollapsed: this.collapsed.has(category.id),
                isActive: this.args.activeCategory?.id === category.id,
            });

            if (!this.collapsed.has(category.id)) {
                children.forEach((child) => walk(child, depth + 1));
            }
        };

        roots.forEach((root) => walk(root, 0));

        return rows;
    }

    get groups() {
        const summary = this.args.summary ?? {};

        return [
            { id: 'out-of-stock', icon: 'box-open', label: this.intl.t('storefront.products.groups.out-of-stock'), count: summary.out_of_stock ?? 0 },
            { id: 'drafts', icon: 'pen-ruler', label: this.intl.t('storefront.products.groups.drafts'), count: summary.draft ?? 0 },
            { id: 'recommended', icon: 'star', label: this.intl.t('storefront.products.groups.recommended'), count: summary.recommended ?? 0 },
            { id: 'on-sale', icon: 'badge-percent', label: this.intl.t('storefront.products.groups.on-sale'), count: summary.on_sale ?? 0 },
            { id: 'uncategorized', icon: 'folder-open', label: this.intl.t('storefront.products.groups.uncategorized'), count: summary.uncategorized ?? 0 },
        ].map((group) => ({ ...group, isActive: this.args.activeGroup === group.id }));
    }

    @action toggleCollapse(category, event) {
        event?.stopPropagation?.();
        const next = new Set(this.collapsed);

        if (next.has(category.id)) {
            next.delete(category.id);
        } else {
            next.add(category.id);
        }

        this.collapsed = next;
    }
}
