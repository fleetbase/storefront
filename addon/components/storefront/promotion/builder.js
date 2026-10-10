import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const STEPS = ['what', 'when', 'targets', 'schedule', 'review'];

/**
 * The promotion builder: a stepped panel. Done steps collapse to one line so the whole rule
 * stays readable; the rule semantics are the server's PromotionEngine.
 */
export default class StorefrontPromotionBuilderComponent extends Component {
    @service store;
    @service intl;
    @service notifications;
    @service fetch;
    @tracked step = this.args.step ?? 'what';
    @tracked products = [];
    @tracked categories = [];
    @tracked excludedProducts = [];
    @tracked segment = null;
    @tracked announce = false;

    types = ['percentage', 'fixed_amount', 'free_delivery', 'bogo'];
    triggers = ['automatic', 'code'];
    statuses = ['draft', 'active', 'paused', 'ended'];

    constructor() {
        super(...arguments);
        this.loadTargets();
    }

    get promotion() {
        return this.args.promotion;
    }

    get currency() {
        return this.promotion?.currency ?? this.args.currency ?? 'USD';
    }

    get storeId() {
        return this.args.storeId ?? this.promotion?.owner_uuid;
    }

    get appliesTo() {
        return this.promotion?.applies_to ?? {};
    }

    get bogo() {
        return this.promotion?.bogo_config ?? {};
    }

    get stepIndex() {
        return STEPS.indexOf(this.step);
    }

    get isLastStep() {
        return this.stepIndex === STEPS.length - 1;
    }

    get steps() {
        return STEPS.map((id, index) => ({
            id,
            number: index + 1,
            label: this.intl.t(`storefront.promotions.builder.steps.${id}`),
            summary: this.summaryFor(id),
            isActive: id === this.step,
            isDone: index < this.stepIndex,
        }));
    }

    get valueSummary() {
        const promotion = this.promotion;

        switch (promotion?.type) {
            case 'percentage':
                return this.intl.t('storefront.promotions.builder.summary.percentage', { value: promotion.value ?? 0 });
            case 'fixed_amount':
                return this.intl.t('storefront.promotions.builder.summary.fixed', {
                    amount: this.intl.formatNumber((promotion.value ?? 0) / 100, { style: 'currency', currency: this.currency }),
                });
            case 'free_delivery':
                return this.intl.t('storefront.promotions.types.free_delivery');
            case 'bogo':
                return this.intl.t('storefront.promotions.card.bogo-summary', { buy: this.bogo.buy_quantity ?? 1, get: this.bogo.get_quantity ?? 1 });
            default:
                return '';
        }
    }

    summaryFor(id) {
        const promotion = this.promotion;

        switch (id) {
            case 'what':
                return [this.valueSummary, promotion?.name ? `"${promotion.name}"` : null].filter(Boolean).join(' · ');
            case 'when':
                if (promotion?.first_order_only) {
                    return this.intl.t('storefront.promotions.card.first-order');
                }
                return promotion?.trigger === 'code' ? this.intl.t('storefront.promotions.builder.summary.with-code') : this.intl.t('storefront.promotions.builder.summary.automatic');
            case 'targets': {
                const parts = [];
                if (this.products.length) parts.push(this.intl.t('storefront.promotions.card.products-count', { count: this.products.length }));
                if (this.categories.length) parts.push(this.intl.t('storefront.promotions.card.categories-count', { count: this.categories.length }));
                if (this.excludedProducts.length) parts.push(this.intl.t('storefront.promotions.builder.summary.excluded', { count: this.excludedProducts.length }));
                return parts.length ? parts.join(' · ') : this.intl.t('storefront.promotions.card.whole-store');
            }
            case 'schedule': {
                const parts = [];
                if (promotion?.runsFrom || promotion?.runsUntil) parts.push([promotion.runsFrom, promotion.runsUntil].filter(Boolean).join(' – '));
                else parts.push(this.intl.t('storefront.promotions.card.always'));
                if (promotion?.usage_limit) parts.push(this.intl.t('storefront.promotions.builder.summary.limit', { count: promotion.usage_limit }));
                if (promotion?.min_subtotal)
                    parts.push(
                        this.intl.t('storefront.promotions.card.cart-total-above', {
                            amount: this.intl.formatNumber(promotion.min_subtotal / 100, { style: 'currency', currency: this.currency }),
                        })
                    );
                return parts.join(' · ');
            }
            default:
                return '';
        }
    }

    get issues() {
        const promotion = this.promotion;
        const issues = [];

        if (!String(promotion?.name ?? '').trim()) {
            issues.push(this.intl.t('storefront.promotions.builder.issues.name'));
        }

        if (['percentage', 'fixed_amount'].includes(promotion?.type) && !(promotion.value > 0)) {
            issues.push(this.intl.t('storefront.promotions.builder.issues.value'));
        }

        if (promotion?.type === 'percentage' && promotion.value > 100) {
            issues.push(this.intl.t('storefront.promotions.builder.issues.percent'));
        }

        if (promotion?.starts_at && promotion?.ends_at && new Date(promotion.ends_at) <= new Date(promotion.starts_at)) {
            issues.push(this.intl.t('storefront.promotions.builder.issues.window'));
        }

        return issues;
    }

    get canSave() {
        return this.issues.length === 0 && !this.save.isRunning;
    }

    async loadTargets() {
        this.products = await this.findAll('product', this.appliesTo.products);
        this.categories = await this.findAll('category', this.appliesTo.categories);
        this.excludedProducts = await this.findAll('product', this.appliesTo.exclude_products);

        if (this.appliesTo.segment) {
            this.segment = await this.store.findRecord('customer-segment', this.appliesTo.segment).catch(() => null);
        }
    }

    async findAll(modelName, ids = []) {
        const records = await Promise.all((ids ?? []).map((id) => this.store.findRecord(modelName, id).catch(() => null)));

        return records.filter(Boolean);
    }

    @action goTo(step) {
        this.step = step.id ?? step;
    }

    @action next() {
        if (!this.isLastStep) {
            this.step = STEPS[this.stepIndex + 1];
        }
    }

    @action back() {
        if (this.stepIndex > 0) {
            this.step = STEPS[this.stepIndex - 1];
        }
    }

    @action setTargets(key, property, models) {
        this[property] = models;
        this.promotion.applies_to = { ...this.appliesTo, [key]: models.map((model) => model.id) };
    }

    @action setSegment(segment) {
        this.segment = segment;
        this.promotion.applies_to = { ...this.appliesTo, segment: segment?.id ?? null };
    }

    @action setBogo(key, { target }) {
        const value = target.value === '' ? null : Number(target.value);
        this.promotion.bogo_config = { ...this.bogo, [key]: value };
    }

    @action setField(property, value) {
        this.promotion[property] = value?.target ? value.target.value : value;
    }

    @action setNumber(property, { target }) {
        this.promotion[property] = target.value === '' ? null : Number(target.value);
    }

    @action setMoney(property, value) {
        this.promotion[property] = value === 0 ? null : value;
    }

    @action setFirstOrderOnly(enabled) {
        this.promotion.first_order_only = enabled;
    }

    @action toggleAnnounce(enabled) {
        this.announce = enabled;
    }

    @task *save(status = null) {
        if (status) {
            this.promotion.status = status;
        }

        try {
            yield this.promotion.save();
            this.notifications.success(this.intl.t('storefront.promotions.list.saved-success'));
            this.args.onSaved?.(this.promotion, { announce: this.announce });
            this.args.onClose?.();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action close() {
        this.args.onClose?.();
    }
}
