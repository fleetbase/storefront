import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

export default class StorefrontOrderDetailsRegisteredTabComponent extends Component {
    @service('universe/menu-service') menuService;

    get tab() {
        return this.menuService.lookupMenuItem('storefront:component:order:details', this.args.class);
    }
}
