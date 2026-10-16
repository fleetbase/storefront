import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

// A phone number in a note: an optional +, then at least 7 digits with spaces, dots or dashes between.
const PHONE = /(\+?\d[\d\s.-]{5,}\d)/g;

/**
 * The customer's note from checkout, at the top of the order panel: delivery instructions, a
 * gate code, another number to call. Line breaks are kept and phone numbers can be called.
 */
export default class StorefrontOrderPanelCustomerNoteComponent extends Component {
    @service intl;

    get note() {
        const notes = this.args.order?.notes;
        return typeof notes === 'string' && notes.trim() ? notes.trim() : null;
    }

    get customerFirstName() {
        return String(this.args.order?.customer?.name ?? this.intl.t('storefront.common.customer')).split(' ')[0];
    }

    /** The note as text and phone-number parts, in order. */
    get parts() {
        const note = this.note;
        if (!note) return [];
        const parts = [];
        let last = 0;
        for (const match of note.matchAll(PHONE)) {
            const digits = match[0].replace(/[^\d+]/g, '');
            if (digits.replace('+', '').length < 7) continue;
            if (match.index > last) parts.push({ text: note.slice(last, match.index) });
            parts.push({ text: match[0], tel: `tel:${digits}` });
            last = match.index + match[0].length;
        }
        if (last < note.length) parts.push({ text: note.slice(last) });
        return parts;
    }
}
