import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action, computed } from '@ember/object';

export default class ScheduleManagerComponent extends Component {
    @service notifications;
    @service modalsManager;
    @service store;

    @computed('args.subject.hours.@each.id', 'hours.length') get schedule() {
        const schedule = {};
        const week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        const { subject } = this.args;
        const { hours } = subject;

        for (let i = 0; i < week.length; i++) {
            const day = week.objectAt(i);

            schedule[day] = [];
        }

        for (let i = 0; i < hours.length; i++) {
            const hour = hours.objectAt(i);

            schedule[hour.day_of_week].pushObject(hour);
        }

        return schedule;
    }

    @computed('args.hourModelType') get hourModelType() {
        const { hourModelType } = this.args;

        return hourModelType ?? 'store-hour';
    }

    @action addHours(subject, day) {
        const { subjectKey } = this.args;

        const hours = this.store.createRecord(this.hourModelType, {
            [subjectKey]: subject.id,
            day_of_week: day,
        });

        this.modalsManager.show('modals/add-store-hours', {
            title: this.intl.t('storefront.components.schedule-manager-title',{Day: day}),
            acceptButtonText: 'Add hours',
            acceptButtonIcon: 'save',
            hours,
            confirm: (modal) => {
                modal.startLoading();

                return hours.save().then((hours) => {
                    subject.hours.pushObject(hours);
                    this.notifications.success(`New hours added for ${day}`);
                });
            },
        });
    }

    @action removeHours(hours) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.components.schedule-manager.title-hour'),
            body: this.intl.t('storefront.components.schedule-manager.body'),
            acceptButtonIcon: 'trash',
            confirm: (modal) => {
                modal.startLoading();

                return hours.destroyRecord();
            },
        });
    }
}
