import Component from '@glimmer/component';
import { get } from '@ember/object';
import { relationValue } from '@fleetbase/ember-ui/utils/resource-registry';
import { rankOf } from '../../../../../utils/order-groups';

export default class StorefrontNetworkOrdersCellDriverComponent extends Component {
    get row() {
        return this.args.row;
    }

    get driver() {
        if (this.row?.isGroup) {
            return this.row.driver;
        }

        return relationValue(this.row, 'driver_assigned') ?? null;
    }

    get isPickup() {
        return Boolean(this.row?.isGroup ? this.row.is_pickup : get(this.row, 'meta.is_pickup'));
    }

    get driverCount() {
        return this.row?.isGroup ? this.row.driverCount : this.driver ? 1 : 0;
    }

    get isTerminal() {
        return this.row?.isGroup ? this.row.allTerminal : rankOf(this.row?.status) >= 4;
    }

    get canAssign() {
        return !this.row?.isGroup && !this.isPickup && !this.driver && !this.isTerminal && typeof this.args.column?.onAssign === 'function';
    }
}
