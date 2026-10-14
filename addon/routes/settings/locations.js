import Route from '@ember/routing/route';

export default class SettingsLocationsRoute extends Route {
    model() {
        return this.modelFor('settings').locations;
    }
}
