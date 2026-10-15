import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { set } from '@ember/object';
import { createCache, getValue } from '@glimmer/tracking/primitives/cache';

/**
 * A record whose changed attributes are kept where autotracking cannot see them, as Ember Data's
 * `changedAttributes()` is: only the controller's own bookkeeping tells the save bar to re-render.
 */
function untrackedRecord(attributes = {}) {
    const changes = {};

    return {
        ...attributes,
        options: { ...(attributes.options ?? {}) },
        set(key, value) {
            if (!key.includes('.')) {
                changes[key] = value;
            }
            set(this, key, value);
        },
        changedAttributes() {
            return Object.fromEntries(Object.keys(changes).map((key) => [key, [undefined, changes[key]]]));
        },
    };
}

module('Unit | Controller | settings/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:settings/index');
        assert.ok(controller);
    });

    test('a toggle, select or picker marks the store settings changed so Save is enabled', function (assert) {
        const controller = this.owner.lookup('controller:settings/index');
        controller.model = untrackedRecord({ online: false, options: {} });
        controller.snapshotOptions();

        const isDirty = createCache(() => controller.isDirty);
        assert.false(getValue(isDirty), 'nothing changed yet');

        controller.setAttribute('online', true);
        assert.true(getValue(isDirty), 'switching the store online enables Save');
        assert.true(controller.model.online);
    });
});
