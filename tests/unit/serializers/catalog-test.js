import { module, test } from 'qunit';

import { setupTest } from 'dummy/tests/helpers';

module('Unit | Serializer | catalog', function (hooks) {
    setupTest(hooks);

    // Replace this with your real tests.
    test('it exists', function (assert) {
        let store = this.owner.lookup('service:store');
        let serializer = store.serializerFor('catalog');

        assert.ok(serializer);
    });

    test('it serializes records', function (assert) {
        let store = this.owner.lookup('service:store');
        let record = store.createRecord('catalog', {});

        let serializedRecord = record.serialize();

        assert.ok(serializedRecord);
    });
});
