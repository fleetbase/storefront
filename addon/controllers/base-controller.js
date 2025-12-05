import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class BaseController extends Controller {
    @service hostRouter;

    @action transitionToRoute(route, ...args) {
        return this.hostRouter.transitionTo(`console.storefront.${route}`, ...args);
    }
}
