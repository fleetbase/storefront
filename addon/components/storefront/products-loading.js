import Component from '@glimmer/component';
import { getOwner } from '@ember/application';

/**
 * The products page while its list loads, drawn in whichever view the user chose: a cards
 * grid for the grid view, table rows for the list view.
 */
export default class StorefrontProductsLoadingComponent extends Component {
    get isList() {
        return getOwner(this).lookup('controller:products/index')?.viewMode === 'list';
    }
}
