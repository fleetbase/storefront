import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { run } from '@ember/runloop';

module('Unit | Controller | catalogs/index/edit | category modal', function (hooks) {
    setupTest(hooks);

    function setup(owner, categoryAttributes = {}) {
        const controller = owner.lookup('controller:catalogs/index/edit');
        const store = owner.lookup('service:store');
        const shown = [];
        const toasts = [];
        const uploads = [];
        const category = run(() =>
            store.push(
                store.normalize('catalog-category', {
                    uuid: 'cat-uuid-1',
                    name: 'Burgers',
                    description: 'Old',
                    icon_file_uuid: 'file-old',
                    icon_url: 'https://cdn.test/old.png',
                    company_uuid: 'company-1',
                    ...categoryAttributes,
                })
            )
        );
        const catalog = run(() => store.createRecord('catalog', { name: 'Lunch' }));

        run(() => catalog.categories.pushObject(category));
        controller.catalog = catalog;
        controller.snapshot();
        controller.intl = { t: (key, params) => (params?.name ? `${key}:${params.name}` : key) };
        controller.notifications = { warning: (message) => toasts.push(message), success: (message) => toasts.push(message), serverError: (error) => toasts.push(error.message) };
        controller.modalsManager = { show: (name, options) => shown.push({ name, options }) };
        controller.fetch = {
            uploadFile: {
                perform: (file, params, callback) => {
                    uploads.push(params);
                    callback({ id: 'file-new', url: 'https://cdn.test/new.png' });
                },
            },
        };
        controller.currentUser = { companyId: 'company-session' };

        return { controller, category, shown, toasts, uploads };
    }

    function modal() {
        const calls = [];

        return { calls, done: () => calls.push('done') };
    }

    test('edit opens the category modal on the catalog category with its title and actions', function (assert) {
        const { controller, category, shown } = setup(this.owner);

        controller.editCategory(category);

        assert.strictEqual(shown.length, 1);
        assert.strictEqual(shown[0].name, 'modals/create-product-category');
        assert.strictEqual(shown[0].options.category, category, 'the form edits the catalog category itself');
        assert.strictEqual(shown[0].options.title, 'storefront.catalogs.editor.edit-category-title:Burgers');
        assert.strictEqual(shown[0].options.acceptButtonText, 'storefront.catalogs.editor.apply-category');
        assert.strictEqual(typeof shown[0].options.uploadNewPhoto, 'function');
    });

    test('apply keeps the edits, trims the name and leaves the catalog unsaved', function (assert) {
        const { controller, category, shown } = setup(this.owner);

        controller.editCategory(category);
        run(() => category.setProperties({ name: '  Smash burgers ', description: 'Two patties' }));
        const handle = modal();
        shown[0].options.confirm(handle);

        assert.deepEqual(handle.calls, ['done']);
        assert.strictEqual(category.name, 'Smash burgers');
        assert.strictEqual(category.description, 'Two patties');
        assert.true(controller.isDirty, 'the save bar offers to save the catalog');
    });

    test('apply with an empty name warns and keeps the modal open', function (assert) {
        const { controller, category, shown, toasts } = setup(this.owner);

        controller.editCategory(category);
        run(() => category.set('name', '   '));
        const handle = modal();
        shown[0].options.confirm(handle);

        assert.deepEqual(handle.calls, [], 'the modal is not closed');
        assert.deepEqual(toasts, ['storefront.catalogs.editor.category-name-required']);
    });

    test('cancel puts the name, description and icon back', function (assert) {
        const { controller, category, shown } = setup(this.owner);

        controller.editCategory(category);
        run(() => category.setProperties({ name: 'Changed', description: 'Changed too' }));
        shown[0].options.uploadNewPhoto({ name: 'icon.png' });
        const handle = modal();
        shown[0].options.decline(handle);

        assert.deepEqual(handle.calls, ['done']);
        assert.strictEqual(category.name, 'Burgers');
        assert.strictEqual(category.description, 'Old');
        assert.strictEqual(category.icon_file_uuid, 'file-old');
        assert.strictEqual(category.icon_url, 'https://cdn.test/old.png');
        assert.false(controller.isDirty);
    });

    test('an uploaded icon is set on the category and linked to it', function (assert) {
        const { controller, category, shown, uploads } = setup(this.owner);

        controller.editCategory(category);
        shown[0].options.uploadNewPhoto({ name: 'icon.png' });

        assert.deepEqual(uploads, [{ path: 'uploads/company-1/catalog-category-icon/burgers', subject_type: 'category', type: 'category_icon', subject_uuid: 'cat-uuid-1' }]);
        assert.strictEqual(category.icon_file_uuid, 'file-new');
        assert.strictEqual(category.icon_url, 'https://cdn.test/new.png');
        assert.true(controller.isDirty, 'a new icon is an unsaved change');
    });

    test('a category added on the page uploads its icon without a subject yet', function (assert) {
        const { controller, uploads } = setup(this.owner);
        const store = this.owner.lookup('service:store');
        const fresh = run(() => store.createRecord('catalog-category', { name: 'Hot Drinks' }));

        controller.uploadCategoryIcon(fresh, { name: 'icon.png' });

        assert.deepEqual(uploads, [{ path: 'uploads/company-session/catalog-category-icon/hot-drinks', subject_type: 'category', type: 'category_icon' }]);
        assert.strictEqual(fresh.icon_file_uuid, 'file-new');
    });

    test('duplicating the catalog copies each category description and icon', async function (assert) {
        const { controller } = setup(this.owner, { icon: 'burger', icon_color: '#f97316' });
        let saved;

        controller.store = {
            createRecord: (type, attributes) => {
                const record = { type, ...attributes, categories: [], products: [], save: () => Promise.resolve(record) };
                record.categories.pushObject = (item) => record.categories.push(item);
                record.products.pushObject = (item) => record.products.push(item);
                if (type === 'catalog') {
                    saved = record;
                }
                return record;
            },
        };
        controller.hostRouter = { transitionTo: () => {} };

        await controller.duplicate();

        const [clone] = saved.categories;
        assert.strictEqual(clone.name, 'Burgers');
        assert.strictEqual(clone.description, 'Old');
        assert.strictEqual(clone.icon, 'burger');
        assert.strictEqual(clone.icon_color, '#f97316');
        assert.strictEqual(clone.icon_file_uuid, 'file-old');
        assert.strictEqual(clone.icon_url, 'https://cdn.test/old.png');
        assert.strictEqual(clone.for, 'storefront_catalog');
    });
});
