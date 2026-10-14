import OrderDetailsDocumentsComponent from './details/documents';
import { inject as service } from '@ember/service';
import { isValid } from 'date-fns';

/**
 * The 11a "Details and documents" section, as Details / Metadata / Files tabs: the order's
 * identifiers and dates as a grid, all of its metadata in the ember-ui viewer, and its files in
 * the ember-ui dropzone (the upload logic is the documents component's).
 */
export default class StorefrontOrderPanelDetailsComponent extends OrderDetailsDocumentsComponent {
    @service intl;
    @service storefrontOrderActions;

    /** A time on the order's store clock (date-fns pattern), not the browser's. */
    storeTime(value, pattern) {
        return this.storefrontOrderActions.formatForOrder(this.args.order ?? this.order, value, pattern);
    }

    get order() {
        return this.args.order;
    }

    get tabs() {
        const t = (key, options) => this.intl.t(`storefront.order.panel.${key}`, options);

        return [
            { id: 'details', label: t('details-tabs.details') },
            { id: 'metadata', label: t('details-tabs.metadata'), badge: this.metadataCount || null },
            { id: 'files', label: t('details-tabs.files'), badge: this.files.length || null },
        ];
    }

    get identifiers() {
        const t = (key) => this.intl.t(`storefront.order.panel.${key}`);
        const order = this.order ?? {};
        const when = (value) => {
            const date = value ? new Date(value) : null;
            return date && isValid(date) ? this.storeTime(date, 'd MMM yyyy HH:mm') : null;
        };

        return [
            { label: t('order-id'), value: order.public_id, mono: true, copy: true },
            order.tracking_number?.tracking_number ? { label: t('tracking-number'), value: order.tracking_number.tracking_number, mono: true, copy: true } : null,
            order.internal_id ? { label: t('internal-id'), value: order.internal_id, mono: true, copy: true } : null,
            order.meta?.checkout_id ? { label: t('checkout'), value: order.meta.checkout_id, mono: true, copy: true } : null,
            when(order.created_at) ? { label: t('created'), value: when(order.created_at) } : null,
            when(order.scheduled_at) ? { label: t('scheduled'), value: when(order.scheduled_at) } : null,
            when(order.updated_at) ? { label: t('updated'), value: when(order.updated_at) } : null,
            this.args.overview?.tracking_url ? { label: t('tracking-page'), value: t('open'), href: this.args.overview.tracking_url } : null,
        ].filter(Boolean);
    }

    get metadataCount() {
        return Object.keys(this.order?.meta ?? {}).length;
    }

    get files() {
        const files = this.order?.files;
        return files?.toArray?.() ?? Array.from(files ?? []);
    }
}
