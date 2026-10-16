import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { run } from '@ember/runloop';

module('Unit | Controller | products | category icon upload', function (hooks) {
    setupTest(hooks);

    // fetch.uploadFile hands its callback the File record it pushed into the store.
    function stub(owner, controller) {
        const store = owner.lookup('service:store');
        const shown = [];
        const uploads = [];

        controller.intl = { t: (key) => key };
        controller.notifications = { success: () => {} };
        controller.modalsManager = { show: (name, options) => shown.push({ name, options }) };
        controller.currentUser = { companyId: 'company-session', getOption: () => 'store-1' };
        controller.fetch = {
            uploadFile: {
                perform: (file, params, callback) => {
                    uploads.push(params);
                    callback(run(() => store.push(store.normalize('file', { uuid: 'file-new', url: 'https://cdn.test/new.png' }))));
                },
            },
        };

        return { store, shown, uploads };
    }

    test('editing a category links the uploaded icon file without writing it to icon', function (assert) {
        const controller = this.owner.lookup('controller:products/index/category');
        const { store, shown, uploads } = stub(this.owner, controller);
        const category = run(() =>
            store.push(store.normalize('category', { uuid: 'cat-uuid-1', name: 'Hot Drinks', icon: 'mug-hot', icon_file_uuid: 'file-old', company_uuid: 'company-1' }))
        );

        controller.editCategory(category);
        shown[0].options.uploadNewPhoto({ name: 'icon.png' });

        assert.deepEqual(uploads, [{ path: 'uploads/company-1/product-category-icon/hot-drinks', subject_uuid: 'cat-uuid-1', subject_type: 'category', type: 'category_icon' }]);
        assert.strictEqual(category.icon_file_uuid, 'file-new');
        assert.strictEqual(category.icon_url, 'https://cdn.test/new.png');
        assert.strictEqual(category.icon, 'mug-hot', 'the icon name is left alone');
        assert.strictEqual(category.serialize().icon, 'mug-hot', 'the save payload carries no stringified file record');
    });

    test('a new product category links the uploaded icon file without writing it to icon', function (assert) {
        const controller = this.owner.lookup('controller:products/index');
        const { shown, uploads } = stub(this.owner, controller);

        controller.createNewProductCategory();
        const { category, uploadNewPhoto } = shown[0].options;
        run(() => category.set('name', 'Cold Drinks'));
        uploadNewPhoto({ name: 'icon.png' });

        assert.strictEqual(uploads.length, 1);
        assert.strictEqual(uploads[0].path, 'uploads/company-session/product-category-icon/cold-drinks');
        assert.strictEqual(category.icon_file_uuid, 'file-new');
        assert.strictEqual(category.icon_url, 'https://cdn.test/new.png');
        assert.strictEqual(category.icon, undefined, 'icon is not set to the file');
        assert.strictEqual(category.serialize().icon, null, 'the save payload carries no stringified file record');
    });
});
