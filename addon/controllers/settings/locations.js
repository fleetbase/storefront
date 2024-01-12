import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { alias } from '@ember/object/computed';
import { action } from '@ember/object';
import Point from '@fleetbase/fleetops-data/utils/geojson/point';

export default class SettingsLocationsController extends Controller {
    /**
     * Inject the `notifications` service
     *
     * @var {Service}
     */
    @service notifications;

    /**
     * Inject the `modals-manager` service
     *
     * @var {Service}
     */
    @service modalsManager;

    /**
     * Inject the `crud` service
     *
     * @var {Service}
     */
    @service crud;

    /**
     * Inject the `store` service
     *
     * @var {Service}
     */
    @service store;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * Inject the `storefront` service
     *
     * @var {Service}
     */
    @service storefront;

    @alias('storefront.activeStore') activeStore;

    daysOfTheWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * Create a new store location
     *
     * @void
     */
    @action createNewLocation() {
        const place = this.store.createRecord('place');
        const storeLocation = this.store.createRecord('store-location', {
            store_uuid: this.activeStore.id,
            place,
        });

        return this.editStoreLocation(storeLocation, {
            title: this.intl.t('storefront.controllers.settings.locations.title'),
            acceptButtonText: 'Add new Location',
            acceptButtonIcon: 'save',
        });
    }

    @action async editStoreLocation(storeLocation, options = {}) {
        await storeLocation.loadPlace();

        let { place } = storeLocation;

        if (!place) {
            place = this.store.createRecord('place', {
                name: storeLocation.name,
            });
        }

        this.modalsManager.show('modals/store-location-form', {
            title: this.intl.t('storefront.controllers.settings.locations.title-edit'),
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            place,
            autocomplete: (selected) => {
                const coordinatesInputComponent = this.modalsManager.getOption('coordinatesInputComponent');

                place.setProperties({ ...selected });

                if (coordinatesInputComponent) {
                    const [longitude, latitude] = selected.location.coordinates;
                    coordinatesInputComponent.updateCoordinates(latitude, longitude);
                }
            },
            setCoordinatesInput: (coordinatesInputComponent) => {
                this.modalsManager.setOption('coordinatesInputComponent', coordinatesInputComponent);
            },
            updatePlaceCoordinates: ({ latitude, longitude }) => {
                const location = new Point(longitude, latitude);

                place.setProperties({ location });
            },
            confirm: (modal) => {
                modal.startLoading();

                return place.save().then((place) => {
                    storeLocation.setProperties({
                        place_uuid: place.id,
                        name: place.name,
                    });

                    return storeLocation
                        .save()
                        .then((storeLocation) => {
                            storeLocation.setProperties({
                                place,
                            });
                            this.notifications.success(`${place.get('name') || place.get('street1')} store location saved.`);
                            return this.hostRouter.refresh();
                        })
                        .catch((error) => {
                            modal.stopLoading();
                            this.notifications.serverError(error);
                        });
                });
            },
            ...options,
        });
    }

    @action removeStoreLocation(storeLocation) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.controllers.settings.locations.title-location'),
            body: this.intl.t('storefront.controllers.settings.locations.body'),
            confirm: (modal) => {
                modal.startLoading();

                return storeLocation.destroyRecord();
            },
        });
    }

    /**
     * Create a new store location
     *
     * @void
     */
    @action addHours(storeLocation, day) {
        const storeHours = this.store.createRecord('store-hour', {
            store_location_uuid: storeLocation.id,
            day_of_week: day,
        });

        this.modalsManager.show('modals/add-store-hours', {
            title: this.intl.t('storefront.controllers.settings.locations.title-hour', {Day: day}),
            acceptButtonText: 'Add hours',
            acceptButtonIcon: 'save',
            storeHours,
            confirm: (modal) => {
                modal.startLoading();

                return storeHours.save().then((storeHours) => {
                    storeLocation.hours.pushObject(storeHours);
                    this.notifications.success(this.intl.t('storefront.controllers.settings.location.success',{Day: day}));
                });
            },
        });
    }

    @action removeHours(storeHours) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.controllers.settings.locations.title-remove'),
            body: this.intl.t('storefront.controllers.settings.locations.body-location'),
            confirm: (modal) => {
                modal.startLoading();

                return storeHours.destroyRecord();
            },
        });
    }
}
