import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * The next action for an order promoted to a button, the rest behind an ellipsis.
 * A checkout group has no action of its own; its orders do.
 */
export default class StorefrontNetworkOrdersCellActionsComponent extends Component {
    @service storefrontOrderActions;

    get items() {
        const { row, column } = this.args;

        if (row?.isGroup) {
            return [];
        }

        return this.storefrontOrderActions.actionItemsFor(row, column?.onChange);
    }

    get primary() {
        return this.items.find((item) => item.id === 'perform-workflow-action') ?? null;
    }

    get secondary() {
        const primary = this.primary;
        const rest = this.items.filter((item) => item !== primary);
        const { row, column } = this.args;

        return [
            { text: 'View order', icon: 'eye', fn: () => column?.onView?.(row) },
            ...rest,
            { separator: true },
            { text: 'Delete order', icon: 'trash', class: 'text-danger', fn: () => column?.onDelete?.(row) },
        ];
    }

    @action run(item) {
        if (typeof item?.fn === 'function') {
            item.fn();
        }
    }
}
