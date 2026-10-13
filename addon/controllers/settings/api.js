import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class SettingsApiController extends Controller {
    @service storefront;

    @action rotateKey() {
        this.storefront.rotateKey(this.model);
    }
}
