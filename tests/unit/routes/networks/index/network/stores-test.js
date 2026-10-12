import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Route | networks/index/network/stores', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        // The engine's dummy app has no console host to provide the host router.
        this.owner.register('service:hostRouter', class extends Service {});
    });

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:networks/index/network/stores');
        assert.ok(route);
    });
});
