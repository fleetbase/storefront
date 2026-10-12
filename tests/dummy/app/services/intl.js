import IntlService from 'ember-intl/services/intl';

/**
 * ember-intl builds a formatter for every bundled locale when the service is
 * created. Headless Chrome ships no number-format data for some of them
 * (Mongolian among them), which ember-intl's default handler turns into a thrown
 * error inside whichever test first touches intl. Missing locale data and
 * missing translations are not what any test here checks, so both are ignored.
 */
export default class DummyIntlService extends IntlService {
    onIntlError(error) {
        if (error?.code === 'MISSING_DATA' || error?.code === 'MISSING_TRANSLATION') {
            return;
        }

        super.onIntlError(error);
    }
}
