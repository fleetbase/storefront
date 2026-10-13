import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { format } from 'date-fns';

/**
 * The 11a notes: the customer's note from checkout and the store's notes on the order. Notes
 * written here are internal: the customer never sees them.
 */
export default class StorefrontOrderPanelNotesComponent extends Component {
    @service store;
    @service intl;
    @service abilities;
    @service notifications;
    @tracked comments = [];
    @tracked draft = '';

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get order() {
        return this.args.order;
    }

    get subjectUuid() {
        return this.order?.uuid ?? this.order?.id;
    }

    get customerFirstName() {
        return String(this.order?.customer?.name ?? this.intl.t('storefront.common.customer')).split(' ')[0];
    }

    get canAdd() {
        return this.abilities.can('storefront update order');
    }

    get notes() {
        return this.comments.map((comment) => {
            const author = comment.author?.name ?? comment.author?.get?.('name') ?? this.intl.t('storefront.common.store');
            return {
                id: comment.public_id ?? comment.id,
                content: comment.content,
                author: this.intl.t('storefront.order.panel.store-author', { name: author }),
                initials: String(author)
                    .split(/\s+/)
                    .filter(Boolean)
                    .slice(0, 2)
                    .map((part) => part[0].toUpperCase())
                    .join(''),
                time: comment.created_at ? format(comment.created_at, 'd MMM HH:mm') : '',
                internal: (comment.meta?.visibility ?? 'internal') === 'internal',
            };
        });
    }

    @task *load() {
        if (!this.subjectUuid) {
            return;
        }
        try {
            const comments = yield this.store.query('comment', { subject_uuid: this.subjectUuid, withoutParent: 1, sort: 'created_at' });
            this.comments = comments?.toArray?.() ?? Array.from(comments ?? []);
        } catch {
            this.comments = [];
        }
    }

    @task *add() {
        const content = this.draft.trim();
        if (!content) {
            return;
        }

        try {
            const comment = this.store.createRecord('comment', {
                content,
                subject_id: this.order.public_id,
                subject_uuid: this.subjectUuid,
                subject_type: 'storefront:order',
                meta: { visibility: 'internal' },
            });
            yield comment.save();
            this.draft = '';
            yield this.load.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action setDraft(event) {
        this.draft = event.target.value;
    }

    @action submit(event) {
        event.preventDefault();
        this.add.perform();
    }
}
