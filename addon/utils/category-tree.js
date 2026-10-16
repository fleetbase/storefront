import { get } from '@ember/object';

/** Product and catalog categories nest at most three levels: Drinks › Coffee › Cold brew. */
export const MAX_CATEGORY_DEPTH = 3;

const toArray = (categories) => categories?.toArray?.() ?? Array.from(categories ?? []);
const idOf = (category) => get(category, 'id') ?? get(category, 'uuid') ?? null;
const parentOf = (category) => get(category, 'parent_uuid') ?? null;

/**
 * Categories in tree order, parents before their children, each with its depth (0 at the top)
 * and its path ("Drinks › Coffee"). A category whose parent is not in the list is a root.
 *
 * @param {Array} categories
 * @returns {Array<{ category, depth: number, path: string, parentId: string|null }>}
 */
export function flattenCategoryTree(categories, { idOf: idFor = idOf, parentOf: parentFor = parentOf, order = (a, b) => Number(get(a, 'order') ?? 0) - Number(get(b, 'order') ?? 0) } = {}) {
    const all = toArray(categories);
    const ids = new Set(all.map(idFor));
    const childrenOf = (parentId) => all.filter((category) => parentFor(category) === parentId).sort(order);
    const rows = [];
    const seen = new Set();
    const walk = (category, depth, trail) => {
        const id = idFor(category);
        if (seen.has(id)) {
            return;
        }
        seen.add(id);
        const path = [...trail, get(category, 'name')].filter(Boolean);
        rows.push({ category, depth, path: path.join(' › '), parentId: ids.has(parentFor(category)) ? parentFor(category) : null });
        childrenOf(id).forEach((child) => walk(child, depth + 1, path));
    };

    all.filter((category) => !parentFor(category) || !ids.has(parentFor(category)))
        .sort(order)
        .forEach((root) => walk(root, 0, []));

    return rows;
}

/** The ids of a category and every category under it. */
export function categoryAndDescendantIds(categories, category) {
    const all = toArray(categories);
    const ids = new Set([idOf(category)]);
    let level = [idOf(category)];

    while (level.length) {
        level = all.filter((candidate) => level.includes(parentOf(candidate)) && !ids.has(idOf(candidate))).map(idOf);
        level.forEach((id) => ids.add(id));
    }

    return ids;
}

/** How many levels sit under a category: 0 for one without subcategories. */
export function categoryHeight(categories, category) {
    const all = toArray(categories);
    let height = 0;
    let level = [idOf(category)];
    const seen = new Set(level);

    for (;;) {
        level = all.filter((candidate) => level.includes(parentOf(candidate)) && !seen.has(idOf(candidate))).map(idOf);
        if (!level.length) {
            return height;
        }
        level.forEach((id) => seen.add(id));
        height++;
    }
}

/**
 * The categories a category can be placed under: not itself or anything under it, and none that
 * would put it, or the levels under it, past three levels deep.
 *
 * @returns {Array<{ category, depth: number, path: string }>}
 */
export function parentCategoryOptions(categories, category = null) {
    const rows = flattenCategoryTree(categories);
    const excluded = category && idOf(category) ? categoryAndDescendantIds(categories, category) : new Set();
    const height = category && idOf(category) ? categoryHeight(categories, category) : 0;

    return rows.filter((row) => !excluded.has(idOf(row.category)) && row.depth + 1 + 1 + height <= MAX_CATEGORY_DEPTH);
}

/** A category's place in the tree: "Drinks › Coffee › Cold brew". */
export function categoryPath(categories, category) {
    return flattenCategoryTree(categories).find((row) => idOf(row.category) === idOf(category))?.path ?? get(category, 'name') ?? '';
}
