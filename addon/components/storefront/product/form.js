import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, get } from '@ember/object';
import { inject as service } from '@ember/service';

/** Which section of the page holds each field an issue can point at. */
const SECTION_FOR_FIELD = { name: 'product', price: 'pricing', sale_price: 'pricing', category: 'organization', media: 'media' };

/** Languages offered for product translations, as ISO 639-1 codes. */
const TRANSLATION_LANGUAGES = 'ar bg bn cs da de el en es fa fi fr he hi hr hu id it ja kk ko mn ms nl no pl pt ro ru sk sr sv sw th tl tr uk ur vi zh'.split(' ');

/** The fields a translation carries for every product; anything else is a custom field. */
const TRANSLATED_FIELDS = ['name', 'description'];

/** A YouTube video id from a watch, share, shorts or embed link. */
export function youtubeVideoId(url) {
    const match = String(url ?? '').match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/)|youtu\.be\/)([\w-]{11})/);

    return match ? match[1] : null;
}

function number(value) {
    const parsed = parseFloat(value);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * The product editor as one page, like the create order form: a main column of sections
 * (product, media, pricing, variants, add-ons, hours, more) and a rail with status,
 * organization and flags. Validation is inline; the save bar counts changes and jumps to
 * the first section that needs attention.
 */
export default class StorefrontProductFormComponent extends Component {
    @service intl;
    @tracked moreOpen = false;
    /** The language whose fields are on screen in the translations section. */
    @tracked translationLanguage = null;
    /** The key typed for a new custom translation field. */
    @tracked newTranslationKey = '';
    /** A new product shows its inline issues once a save has been tried, not on an empty form. */
    @tracked saveAttempted = false;

    get product() {
        return this.args.product;
    }

    get variantsCount() {
        return this.product?.variants?.length ?? 0;
    }

    get addonCategoriesCount() {
        return this.product?.addon_categories?.length ?? 0;
    }

    get mediaCount() {
        return (this.product?.files?.length ?? 0) + (Array.isArray(this.product?.youtube_urls) ? this.product.youtube_urls.length : 0);
    }

    /** The video links as rows: the link, its YouTube id and thumbnail when it is a valid link. */
    get videoRows() {
        const urls = Array.isArray(this.product?.youtube_urls) ? this.product.youtube_urls : [];

        return urls.map((url, index) => {
            const id = youtubeVideoId(url);

            return { index, url, id, isValid: Boolean(id), thumbnail: id ? `https://img.youtube.com/vi/${id}/mqdefault.jpg` : null };
        });
    }

    @action addVideo() {
        const urls = Array.isArray(this.product.youtube_urls) ? this.product.youtube_urls : [];
        this.product.youtube_urls = [...urls, ''];
    }

    @action setVideo(index, event) {
        const urls = [...(this.product.youtube_urls ?? [])];
        urls[index] = event.target.value.trim();
        this.product.youtube_urls = urls;
    }

    @action removeVideo(index) {
        this.product.youtube_urls = (this.product.youtube_urls ?? []).filter((_, position) => position !== index);
    }

    /** Attached add-on groups with each add-on and whether this product offers it. */
    get addonGroups() {
        const categories = this.product?.addon_categories?.toArray?.() ?? Array.from(this.product?.addon_categories ?? []);

        return categories.map((addonCategory, index) => {
            // pushObject/removeObject notify the array's `[]`; reading it keeps this list in step.
            get(addonCategory, 'excluded_addons.[]');
            const excluded = new Set(addonCategory.excluded_addons ?? []);
            // `category` is a belongsTo proxy; read through it with get().
            const list = get(addonCategory, 'category.addons');
            const addons = list?.toArray?.() ?? Array.from(list ?? []);
            const rows = addons.map((addon) => ({ addon, isOffered: !excluded.has(addon.id) }));

            return {
                index,
                name: addonCategory.name ?? get(addonCategory, 'category.name'),
                addons: rows,
                total: rows.length,
                offered: rows.filter((row) => row.isOffered).length,
            };
        });
    }

    get translations() {
        const translations = this.product?.translations;

        return translations && typeof translations === 'object' && !Array.isArray(translations) ? translations : {};
    }

    languageName(code) {
        try {
            return new Intl.DisplayNames([this.intl.primaryLocale ?? 'en'], { type: 'language' }).of(code) ?? code.toUpperCase();
        } catch {
            return code.toUpperCase();
        }
    }

    /** The languages the product is translated into, each with how many fields are filled. */
    get translationLanguages() {
        const active = this.activeTranslationLanguage;

        return Object.keys(this.translations).map((code) => {
            const values = Object.values(this.translations[code] ?? {});
            return { code, label: this.languageName(code), filled: values.filter((value) => String(value ?? '').trim()).length, isActive: code === active };
        });
    }

    get activeTranslationLanguage() {
        const codes = Object.keys(this.translations);

        return codes.includes(this.translationLanguage) ? this.translationLanguage : (codes[0] ?? null);
    }

    /** Languages that can still be added, by name. */
    get translationLanguageOptions() {
        const used = new Set(Object.keys(this.translations));

        return TRANSLATION_LANGUAGES.filter((code) => !used.has(code))
            .map((code) => ({ value: code, label: this.languageName(code) }))
            .sort((a, b) => a.label.localeCompare(b.label));
    }

    /** The visible language's name and description, then its custom fields. */
    get translationFields() {
        const code = this.activeTranslationLanguage;
        if (!code) {
            return { standard: [], custom: [] };
        }

        const values = this.translations[code] ?? {};
        const standard = TRANSLATED_FIELDS.map((key) => ({
            key,
            value: values[key] ?? '',
            original: this.product?.[key] ?? '',
            isLong: key === 'description',
        }));
        const custom = Object.keys(values)
            .filter((key) => !TRANSLATED_FIELDS.includes(key))
            .map((key) => ({ key, value: values[key] ?? '' }));

        return { standard, custom };
    }

    #writeTranslations(translations) {
        this.product.translations = translations;
    }

    @action selectTranslationLanguage(code) {
        this.translationLanguage = code;
    }

    /** From the language picker: add the chosen language, then put the picker back on its prompt. */
    @action addTranslationLanguageFromPicker(event) {
        const code = event.target.value;
        event.target.value = '';
        this.addTranslationLanguage(code);
    }

    @action addTranslationLanguage(code) {
        if (!code || this.translations[code]) {
            return;
        }

        this.#writeTranslations({ ...this.translations, [code]: { name: '', description: '' } });
        this.translationLanguage = code;
    }

    @action removeTranslationLanguage(code) {
        const next = { ...this.translations };
        delete next[code];
        this.#writeTranslations(next);

        if (this.translationLanguage === code) {
            this.translationLanguage = null;
        }
    }

    @action setTranslationField(key, event) {
        const code = this.activeTranslationLanguage;
        const value = event.target.value;

        this.#writeTranslations({ ...this.translations, [code]: { ...(this.translations[code] ?? {}), [key]: value } });
    }

    @action removeTranslationField(key) {
        const code = this.activeTranslationLanguage;
        const values = { ...(this.translations[code] ?? {}) };
        delete values[key];

        this.#writeTranslations({ ...this.translations, [code]: values });
    }

    @action setNewTranslationKey(event) {
        this.newTranslationKey = event.target.value;
    }

    @action addTranslationField() {
        const code = this.activeTranslationLanguage;
        const key = String(this.newTranslationKey ?? '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');

        if (!code || !key || TRANSLATED_FIELDS.includes(key) || key in (this.translations[code] ?? {})) {
            return;
        }

        this.#writeTranslations({ ...this.translations, [code]: { ...(this.translations[code] ?? {}), [key]: '' } });
        this.newTranslationKey = '';
    }

    get translationsCount() {
        const translations = this.product?.translations;

        return translations && typeof translations === 'object' ? Object.keys(translations).length : 0;
    }

    get metadataCount() {
        const meta = this.product?.meta;

        return meta && typeof meta === 'object' ? Object.keys(meta).length : 0;
    }

    /**
     * What blocks a clean publish, each tied to the tab that holds the field.
     */
    get issues() {
        const product = this.product;
        const issues = [];

        if (!product) {
            return issues;
        }

        if (!String(product.name ?? '').trim()) {
            issues.push({ tab: 'details', field: 'name', message: this.intl.t('storefront.products.form.issues.name') });
        }

        const price = number(product.price);
        const salePrice = number(product.sale_price);

        if (price === null || price < 0) {
            issues.push({ tab: 'details', field: 'price', message: this.intl.t('storefront.products.form.issues.price') });
        }

        if (product.is_on_sale && salePrice !== null && price !== null && salePrice >= price) {
            issues.push({ tab: 'details', field: 'sale_price', message: this.intl.t('storefront.products.form.issues.sale-price') });
        }

        if (product.is_on_sale && salePrice === null) {
            issues.push({ tab: 'details', field: 'sale_price', message: this.intl.t('storefront.products.form.issues.sale-price-missing') });
        }

        if (!product.category_uuid && !product.category?.id && product.status === 'published') {
            issues.push({ tab: 'details', field: 'category', message: this.intl.t('storefront.products.form.issues.category') });
        }

        if (product.status === 'published' && this.mediaCount === 0 && !product.primary_image_url) {
            issues.push({ tab: 'media', field: 'media', message: this.intl.t('storefront.products.form.issues.media') });
        }

        return issues;
    }

    /** Issue messages for one section, shown at its top. */
    issuesFor = (section) =>
        (this.product?.isNew && !this.saveAttempted ? [] : this.issues).filter((issue) => (SECTION_FOR_FIELD[issue.field] ?? 'product') === section).map((issue) => issue.message);

    get dirtyCount() {
        const product = this.product;

        if (!product) {
            return 0;
        }

        if (product.isNew) {
            return 1;
        }

        // Reading the tracked flag makes the count follow edits made anywhere on the record.
        product.hasDirtyAttributes;
        const changed = Object.keys(product.changedAttributes?.() ?? {}).length;
        const dirtyVariants = (product.variants?.toArray?.() ?? []).filter((variant) => variant.hasDirtyAttributes || variant.isNew).length;
        const dirtyAddons = (product.addon_categories?.toArray?.() ?? []).filter((category) => category.hasDirtyAttributes || category.isNew).length;
        const queued = this.args.uploadQueue?.length ?? 0;

        return changed + dirtyVariants + dirtyAddons + queued;
    }

    get isDirty() {
        return this.dirtyCount > 0;
    }

    @action save(...args) {
        this.saveAttempted = true;
        return this.args.onSave?.(...args);
    }

    @action toggleMore() {
        this.moreOpen = !this.moreOpen;
    }

    @action jumpToIssue(issue) {
        const section = SECTION_FOR_FIELD[issue?.field] ?? 'product';
        document.getElementById(`product-section-${section}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
