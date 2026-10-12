import Component from '@glimmer/component';
import { getOwner } from '@ember/application';

/**
 * Yields whether a list page is in a given view, so its loading skeleton draws the same view:
 * `@controller` names the page's controller, `@property` the view field and `@value` the view
 * to test for, read when the skeleton renders.
 */
export default class StorefrontViewLoadingComponent extends Component {
    get matches() {
        const { controller, property, value } = this.args;
        return getOwner(this).lookup(`controller:${controller}`)?.[property] === value;
    }
}
