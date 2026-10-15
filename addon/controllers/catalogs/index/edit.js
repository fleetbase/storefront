import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { flattenCategoryTree, MAX_CATEGORY_DEPTH } from '../../../utils/category-tree';

/** A catalog category's key: its id once saved, a client key until then. */
const keyOf = (category) => category.id ?? category.key ?? null;
/** The key of the category it sits under: a saved parent's id, or a new parent's key. */
const parentKeyOf = (category) => category.parent_uuid ?? category.parent_key ?? null;
const newKey = () => `new-${Math.random().toString(36).slice(2, 10)}`;

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

export default class CatalogsIndexEditController extends Controller {
    @service store;
    @service intl;
    @service notifications;
    @service modalsManager;
    @service hostRouter;
    @service crud;
    @tracked catalog;
    @tracked products = [];
    @tracked stores = [];
    @tracked trucks = [];
    @tracked selectedCategory = null;
    @tracked isPickerOpen = false;
    @tracked pickerQuery = '';
    @tracked pickerSelection = [];
    @tracked newCategoryName = '';
    @tracked renamingCategory = null;
    @tracked renameValue = '';
    @tracked revision = 0;
    /** The open editor section, kept in the URL so links can land on Served by or Hours. */
    queryParams = [{ activeSection: 'section' }];
    @tracked activeSection = 'categories';
    structureSnapshot = '';

    statusOptions = ['draft', 'published'];

    get categories() {
        return this.catalog?.categories?.toArray?.() ?? Array.from(this.catalog?.categories ?? []);
    }

    /** The menu in tree order: Lunch, Lunch › Tacos, Lunch › Tacos › Vegetarian, Drinks. */
    get categoryTree() {
        this.revision;

        return flattenCategoryTree(this.categories, { idOf: keyOf, parentOf: parentKeyOf });
    }

    get categoryRows() {
        const rows = this.categoryTree;

        return rows.map((row, index) => {
            const siblings = rows.filter((candidate) => candidate.parentId === row.parentId);
            const position = siblings.indexOf(row);
            const previous = siblings[position - 1];
            const height = this.levelsUnder(rows, index);

            return {
                category: row.category,
                index,
                depth: row.depth,
                path: row.path,
                count: row.category.products?.length ?? 0,
                isSelected: row.category === this.selectedCategory,
                isFirst: position === 0,
                isLast: position === siblings.length - 1,
                // Under the category above it, if that keeps the branch within three levels.
                canIndent: Boolean(previous) && previous.depth + 1 + height < MAX_CATEGORY_DEPTH,
                canOutdent: row.depth > 0,
                canAddSubcategory: row.depth < MAX_CATEGORY_DEPTH - 1,
            };
        });
    }

    /** How many levels sit under the row at `index` in tree order. */
    levelsUnder(rows, index) {
        let deepest = rows[index].depth;
        for (let next = index + 1; next < rows.length && rows[next].depth > rows[index].depth; next++) {
            deepest = Math.max(deepest, rows[next].depth);
        }

        return deepest - rows[index].depth;
    }

    categoryByKey(key) {
        return key ? (this.categories.find((category) => keyOf(category) === key) ?? null) : null;
    }

    /** Place a category under another (or at the top): a saved parent by id, a new one by its key. */
    setParent(category, parent) {
        if (parent && !keyOf(parent)) {
            parent.set('key', newKey());
        }

        category.setProperties({
            parent_uuid: parent?.id ?? null,
            parent_key: parent && !parent.id ? parent.key : null,
        });
    }

    /** Number the categories in tree order, which keeps each one's place among its siblings. */
    renumber() {
        flattenCategoryTree(this.categories, { idOf: keyOf, parentOf: parentKeyOf }).forEach((row, position) => row.category.set('order', position));
        this.touch();
    }

    get selectedProducts() {
        this.revision;

        return this.selectedCategory?.products?.toArray?.() ?? Array.from(this.selectedCategory?.products ?? []);
    }

    /**
     * The selected category's products with their per-catalog overrides resolved:
     * the price the app charges here and whether the product is sold here.
     */
    get selectedProductRows() {
        this.revision;

        const overrides = this.overridesFor(this.selectedCategory);

        return this.selectedProducts.map((product) => {
            const override = overrides[product.id] ?? {};
            const hasPriceOverride = override.price !== null && override.price !== undefined && override.price !== '';
            const hiddenHere = override.is_available === false;

            return {
                product,
                hasPriceOverride,
                price: hasPriceOverride ? override.price : product.price,
                hiddenHere,
                isEditingPrice: this.editingPriceFor === product.id,
                isAvailable: !hiddenHere && product.is_available,
            };
        });
    }

    @tracked editingPriceFor = null;

    overridesFor(category) {
        const overrides = category?.product_overrides;

        return overrides && typeof overrides === 'object' ? overrides : {};
    }

    setOverride(category, product, patch) {
        const overrides = { ...this.overridesFor(category) };
        const next = { price: null, is_available: null, ...(overrides[product.id] ?? {}), ...patch };

        if (next.price === null && next.is_available === null) {
            delete overrides[product.id];
        } else {
            overrides[product.id] = next;
        }

        category.set('product_overrides', overrides);
        this.touch();
    }

    @action editCatalogPrice(product) {
        this.editingPriceFor = product.id;
    }

    @action setCatalogPrice(category, product, value) {
        const amount = value === null || value === undefined || value === '' ? null : String(value).replace(/[^0-9]/g, '');

        this.setOverride(category, product, { price: amount === '' ? null : amount });
    }

    @action closeCatalogPrice() {
        this.editingPriceFor = null;
    }

    @action clearCatalogPrice(category, product) {
        this.setOverride(category, product, { price: null });
        this.editingPriceFor = null;
    }

    @action setSoldHere(category, product, sold) {
        this.setOverride(category, product, { is_available: sold ? null : false });
    }

    get productIdsInCatalog() {
        this.revision;

        return new Set(this.categories.flatMap((category) => (category.products?.toArray?.() ?? Array.from(category.products ?? [])).map((product) => product.id)));
    }

    /**
     * Products the picker offers: the store's products not yet in this catalog, filtered by the search box.
     */
    get pickerProducts() {
        const inCatalog = this.productIdsInCatalog;
        const query = this.pickerQuery.trim().toLowerCase();

        return this.products
            .filter((product) => !inCatalog.has(product.id))
            .filter(
                (product) =>
                    !query ||
                    String(product.name ?? '')
                        .toLowerCase()
                        .includes(query) ||
                    String(product.sku ?? '')
                        .toLowerCase()
                        .includes(query)
            );
    }

    get productsCount() {
        this.revision;

        return this.catalog?.productsCount ?? 0;
    }

    get outOfStockCount() {
        this.revision;

        return this.categories.flatMap((category) => category.products?.toArray?.() ?? Array.from(category.products ?? [])).filter((product) => product.is_available === false).length;
    }

    get emptyCategoriesCount() {
        this.revision;

        return this.categories.filter((category) => !(category.products?.length > 0)).length;
    }

    get hours() {
        return this.catalog?.hours?.toArray?.() ?? Array.from(this.catalog?.hours ?? []);
    }

    get hoursSummary() {
        const hours = this.hours;

        if (!hours.length) {
            return this.intl.t('storefront.catalogs.editor.no-hours');
        }

        const days = [...new Set(hours.map((hour) => hour.day_of_week))];
        const sample = hours[0];
        const dayLabel = days.length === 7 ? this.intl.t('storefront.catalogs.editor.every-day') : days.map((day) => String(day).slice(0, 3)).join(', ');

        return `${sample.start ?? ''}${sample.end ? ` – ${sample.end}` : ''} · ${dayLabel}`;
    }

    get isVisibleInApp() {
        return this.catalog?.status === 'published';
    }

    get servedBy() {
        return this.catalog?.subjects ?? [];
    }

    /** The header's meta line: id · categories · products · hours · served by. */
    get headerMeta() {
        this.revision;
        const subjects = this.servedBy;
        const trucks = subjects.filter((subject) => subject.type === 'food-truck').length;

        return [
            this.catalog?.public_id,
            this.intl.t('storefront.catalogs.editor.categories-count', { count: this.categories.length }),
            this.intl.t('storefront.catalogs.card.products-count', { count: this.productsCount }),
            this.hoursSummary,
            this.intl.t('storefront.catalogs.editor.served-by-count', { stores: subjects.length - trucks, trucks }),
        ]
            .filter(Boolean)
            .join(' · ');
    }

    get structure() {
        this.revision;

        return JSON.stringify({
            name: this.catalog?.name,
            description: this.catalog?.description,
            status: this.catalog?.status,
            categories: this.categories.map((category) => ({
                id: category.id,
                name: category.name,
                parent: parentKeyOf(category),
                order: Number(category.order ?? 0),
                products: (category.products?.toArray?.() ?? Array.from(category.products ?? [])).map((product) => product.id),
                overrides: this.overridesFor(category),
            })),
        });
    }

    get isDirty() {
        return this.structure !== this.structureSnapshot;
    }

    get dirtySummary() {
        if (!this.isDirty) {
            return null;
        }

        try {
            const before = JSON.parse(this.structureSnapshot || '{}');
            const after = JSON.parse(this.structure);
            const beforeIds = new Set((before.categories ?? []).flatMap((category) => category.products));
            const afterIds = new Set((after.categories ?? []).flatMap((category) => category.products));
            const added = [...afterIds].filter((id) => !beforeIds.has(id)).length;
            const removed = [...beforeIds].filter((id) => !afterIds.has(id)).length;
            const categoriesChanged = (before.categories ?? []).length !== (after.categories ?? []).length;
            const overridesBefore = Object.assign({}, ...(before.categories ?? []).map((category) => category.overrides ?? {}));
            const overridesAfter = Object.assign({}, ...(after.categories ?? []).map((category) => category.overrides ?? {}));
            const overrides = [...new Set([...Object.keys(overridesBefore), ...Object.keys(overridesAfter)])].filter(
                (id) => JSON.stringify(overridesBefore[id] ?? null) !== JSON.stringify(overridesAfter[id] ?? null)
            ).length;

            if (!added && !removed && !categoriesChanged && !overrides) {
                return this.intl.t('storefront.catalogs.editor.unsaved');
            }

            return this.intl.t('storefront.catalogs.editor.unsaved-summary', { added, removed, categories: categoriesChanged ? 1 : 0, overrides });
        } catch {
            return this.intl.t('storefront.catalogs.editor.unsaved');
        }
    }

    snapshot() {
        this.structureSnapshot = this.structure;
        this.revision++;
    }

    touch() {
        this.revision++;
    }

    @action setSection(section) {
        this.activeSection = section;
    }

    @action selectCategory(category) {
        this.selectedCategory = category;
        this.isPickerOpen = false;
    }

    @action setNewCategoryName(event) {
        this.newCategoryName = event.target.value;
    }

    @action addCategory(event) {
        event?.preventDefault?.();
        const name = this.newCategoryName.trim();

        if (!name) {
            return;
        }

        const category = this.store.createRecord('catalog-category', { name, for: 'storefront_catalog', order: this.categories.length, key: newKey() });
        this.catalog.categories.pushObject(category);
        this.newCategoryName = '';
        this.selectedCategory = category;
        this.touch();
    }

    @action startRename(category) {
        this.renamingCategory = category;
        this.renameValue = category.name ?? '';
    }

    @action setRenameValue(event) {
        this.renameValue = event.target.value;
    }

    @action commitRename(event) {
        event?.preventDefault?.();

        if (this.renamingCategory && this.renameValue.trim()) {
            this.renamingCategory.set('name', this.renameValue.trim());
            this.touch();
        }

        this.renamingCategory = null;
    }

    @action cancelRename() {
        this.renamingCategory = null;
    }

    /** Up or down among the categories it shares a parent with. */
    @action moveCategory(category, direction) {
        const tree = this.categoryTree;
        const parentId = tree.find((row) => row.category === category)?.parentId ?? null;
        const siblings = tree.filter((row) => row.parentId === parentId).map((row) => row.category);
        const index = siblings.indexOf(category);
        const target = index + direction;

        if (index < 0 || target < 0 || target >= siblings.length) {
            return;
        }

        const orders = siblings.map((sibling) => Number(sibling.order ?? 0));
        siblings.splice(index, 1);
        siblings.splice(target, 0, category);
        siblings.forEach((sibling, position) => sibling.set('order', orders[position]));
        this.renumber();
    }

    /** Under the category above it, as its last subcategory. */
    @action indentCategory(category) {
        const row = this.categoryRows.find((candidate) => candidate.category === category);
        if (!row?.canIndent) {
            return;
        }

        const tree = this.categoryTree;
        const siblings = tree.filter((candidate) => candidate.parentId === tree[row.index].parentId);
        const previous = siblings[siblings.findIndex((candidate) => candidate.category === category) - 1].category;
        this.setParent(category, previous);
        category.set('order', Number.MAX_SAFE_INTEGER);
        this.renumber();
    }

    /** Up a level, right after the category it was under. */
    @action outdentCategory(category) {
        const parent = this.categoryByKey(parentKeyOf(category));
        if (!parent) {
            return;
        }

        this.setParent(category, this.categoryByKey(parentKeyOf(parent)));
        category.set('order', Number(parent.order ?? 0) + 0.5);
        this.renumber();
    }

    @action addSubcategory(parent) {
        if (!this.categoryRows.find((row) => row.category === parent)?.canAddSubcategory) {
            return;
        }

        const category = this.store.createRecord('catalog-category', {
            name: this.intl.t('storefront.catalogs.editor.new-subcategory-name'),
            for: 'storefront_catalog',
            key: newKey(),
            order: Number.MAX_SAFE_INTEGER,
        });
        this.setParent(category, parent);
        this.catalog.categories.pushObject(category);
        this.renumber();
        this.selectedCategory = category;
        this.startRename(category);
    }

    @action removeCategory(category) {
        return this.modalsManager.confirm({
            title: this.intl.t('storefront.catalogs.editor.remove-category-title', { name: category.name }),
            body: this.intl.t('storefront.catalogs.editor.remove-category-body'),
            acceptButtonText: this.intl.t('common.delete'),
            acceptButtonScheme: 'danger',
            confirm: (modal) => {
                // Its subcategories move up to where it was.
                const parent = this.categoryByKey(parentKeyOf(category));
                this.categories
                    .filter((child) => parentKeyOf(child) === keyOf(category))
                    .forEach((child) => {
                        this.setParent(child, parent);
                        child.set('order', Number(category.order ?? 0) + Number(child.order ?? 0) / 1000);
                    });
                this.catalog.categories.removeObject(category);
                this.renumber();

                if (this.selectedCategory === category) {
                    this.selectedCategory = this.categories[0] ?? null;
                }

                this.touch();
                modal.done();
            },
        });
    }

    @action openPicker(category = this.selectedCategory) {
        if (category) {
            this.selectedCategory = category;
        }

        this.pickerSelection = [];
        this.pickerQuery = '';
        this.isPickerOpen = true;
    }

    @action closePicker() {
        this.isPickerOpen = false;
        this.pickerSelection = [];
    }

    @action setPickerQuery(event) {
        this.pickerQuery = event.target.value;
    }

    @action togglePickerProduct(product) {
        if (this.pickerSelection.includes(product)) {
            this.pickerSelection = this.pickerSelection.filter((item) => item !== product);
        } else {
            this.pickerSelection = [...this.pickerSelection, product];
        }
    }

    @action confirmPicker() {
        const category = this.selectedCategory;

        if (!category) {
            return;
        }

        this.pickerSelection.forEach((product) => {
            if (!category.products.includes(product)) {
                category.products.pushObject(product);
            }
        });
        this.closePicker();
        this.touch();
    }

    @action removeProduct(category, product) {
        category.products.removeObject(product);
        this.touch();
    }

    @action setStatus(status) {
        this.catalog.set('status', status);
        this.touch();
    }

    @action touchField() {
        this.touch();
    }

    /**
     * Stop one store or truck serving this catalog, inline from its row. The API takes the
     * whole served-by set, so the remaining subjects are sent back without this one.
     */
    @action async removeServedBy(subject) {
        const catalog = this.catalog;
        const keep = (subjects) => subjects.filter((item) => item.id !== subject.id);

        try {
            await catalog.assignSubjects({ stores: keep(catalog.storeSubjects), food_trucks: keep(catalog.truckSubjects) });
            this.notifications.success(this.intl.t('storefront.catalogs.editor.served-by-removed', { name: subject.name }));
            this.touch();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action manageServedBy() {
        const catalog = this.catalog;
        const memberStoreIds = new Set(catalog.storeSubjects.map((subject) => subject.id));
        const memberTruckIds = new Set(catalog.truckSubjects.map((subject) => subject.id));

        this.modalsManager.show('modals/assign-catalog-subjects', {
            title: this.intl.t('storefront.catalogs.editor.served-by-title', { name: catalog.name }),
            acceptButtonText: this.intl.t('common.save'),
            acceptButtonIcon: 'save',
            catalog,
            stores: this.stores,
            trucks: this.trucks,
            selectedStores: this.stores.filter((store) => memberStoreIds.has(store.id)),
            selectedTrucks: this.trucks.filter((truck) => memberTruckIds.has(truck.id)),
            setStores: (stores) => this.modalsManager.setOption('selectedStores', Array.from(stores ?? [])),
            setTrucks: (trucks) => this.modalsManager.setOption('selectedTrucks', Array.from(trucks ?? [])),
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await catalog.assignSubjects({ stores: modal.getOption('selectedStores'), food_trucks: modal.getOption('selectedTrucks') });
                    this.notifications.success(this.intl.t('storefront.catalogs.editor.served-by-saved'));
                    this.touch();
                    modal.done();
                } catch (error) {
                    modal.stopLoading();
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @task *save() {
        try {
            yield this.catalog.save();
            this.snapshot();
            this.notifications.success(this.intl.t('storefront.catalogs.editor.saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action discard() {
        this.catalog.rollbackAttributes();
        this.catalog.categories.forEach((category) => {
            if (category.isNew) {
                this.catalog.categories.removeObject(category);
            } else {
                category.rollbackAttributes();
            }
        });
        this.selectedCategory = this.categories[0] ?? null;
        this.hostRouter.refresh();
    }

    @action duplicate() {
        const copy = this.store.createRecord('catalog', {
            store_uuid: this.catalog.store_uuid,
            name: `${this.catalog.name} (copy)`,
            description: this.catalog.description,
            status: 'draft',
        });

        // Clones keep the tree: each finds its parent's clone by key.
        const clones = new Map(
            this.categories.map((category) => [
                keyOf(category),
                this.store.createRecord('catalog-category', { name: category.name, for: 'storefront_catalog', order: category.order, key: newKey() }),
            ])
        );
        this.categories.forEach((category) => {
            const clone = clones.get(keyOf(category));
            clone.set('parent_key', clones.get(parentKeyOf(category))?.key ?? null);
            (category.products?.toArray?.() ?? []).forEach((product) => clone.products.pushObject(product));
            copy.categories.pushObject(clone);
        });

        return copy
            .save()
            .then(() => {
                this.notifications.success(this.intl.t('storefront.catalogs.editor.duplicated'));
                return this.hostRouter.transitionTo('console.storefront.catalogs.index.edit', copy.public_id ?? copy.id);
            })
            .catch((error) => this.notifications.serverError(error));
    }

    @action deleteCatalog() {
        return this.crud.delete(this.catalog, {
            onSuccess: () => this.hostRouter.transitionTo('console.storefront.catalogs.index'),
        });
    }

    @action back() {
        if (this.isDirty) {
            return this.modalsManager.confirm({
                title: this.intl.t('storefront.catalogs.editor.leave-title'),
                body: this.intl.t('storefront.catalogs.editor.leave-body'),
                acceptButtonText: this.intl.t('storefront.catalogs.editor.leave'),
                confirm: (modal) => {
                    modal.done();
                    this.discard();
                    return this.hostRouter.transitionTo('console.storefront.catalogs.index');
                },
            });
        }

        return this.hostRouter.transitionTo('console.storefront.catalogs.index');
    }

    get dayNames() {
        return DAYS;
    }
}
