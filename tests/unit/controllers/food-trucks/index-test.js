import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | food-trucks/index', function (hooks) {
    setupTest(hooks);

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:food-trucks/index');
        assert.ok(controller);
    });

    test('trucks split into active and inactive by their status alone', function (assert) {
        const controller = this.owner.lookup('controller:food-trucks/index');
        const onTheRoad = { id: 't1', status: 'active', online: false, vehicle: { online: false } };
        const parked = { id: 't2', status: 'inactive', online: false, vehicle: { online: true } };
        const unset = { id: 't3', status: null };
        controller.model = [onTheRoad, parked, unset];

        assert.deepEqual(
            controller.statusTabs.map(({ id, count }) => [id, count]),
            [
                ['all', 3],
                ['active', 1],
                ['inactive', 2],
            ],
            'no online or offline tab: the vehicle has no say'
        );

        controller.statusTab = 'active';
        assert.deepEqual(controller.visibleTrucks, [onTheRoad], 'an active truck whose vehicle is offline is active');

        controller.statusTab = 'inactive';
        assert.deepEqual(controller.visibleTrucks, [parked, unset]);
    });
});
