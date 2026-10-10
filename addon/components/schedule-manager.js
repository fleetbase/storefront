import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action, computed } from '@ember/object';

export default class ScheduleManagerComponent extends Component {
    @service notifications;
    @service modalsManager;
    @service store;
    @service intl;

    @computed('args.subject.hours.@each.id', 'hours.length') get schedule() {
        const schedule = {};
        const week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        const { subject } = this.args;
        const { hours } = subject;

        for (const day of week) {
            schedule[day] = [];
        }

        const list = hours?.toArray?.() ?? Array.from(hours ?? []);

        // Hour rows arrive capitalised ("Monday") from the console and lowercase ("monday")
        // from the API and seeds; bucket them by name regardless of case.
        for (const hour of list) {
            const day = week.find((name) => name.toLowerCase() === String(hour.day_of_week ?? '').toLowerCase());

            if (day) {
                schedule[day].push(hour);
            }
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
            title: this.intl.t('storefront.component.schedule-manager.add-new-hours-for-day', { day: day }),
            acceptButtonText: this.intl.t('storefront.component.schedule-manager.add-hours'),
            acceptButtonIcon: 'save',
            hours,
            confirm: (modal) => {
                modal.startLoading();

                return hours.save().then((hours) => {
                    subject.hours.pushObject(hours);
                    this.notifications.success(this.intl.t('storefront.component.schedule-manager.new-hours-added-for-day', { day }));
                });
            },
        });
    }

    @action removeHours(hours) {
        this.modalsManager.confirm({
            title: this.intl.t('storefront.component.schedule-manager.you-wish-to-remove-these-hour'),
            body: this.intl.t('storefront.component.schedule-manager.by-removing-these-operation'),
            acceptButtonIcon: 'trash',
            confirm: (modal) => {
                modal.startLoading();

                return hours.destroyRecord();
            },
        });
    }
}
