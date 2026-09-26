import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

/**
 * Create / edit a promotion. Targeting pickers work on models, while the promotion stores
 * their ids in `applies_to`, so the selected models are loaded when the form opens.
 */
export default class ModalsPromotionFormComponent extends Component {
    @service store;
    @tracked products = [];
    @tracked categories = [];
    @tracked excludedProducts = [];

    types = ['percentage', 'fixed_amount', 'free_delivery', 'bogo'];
    triggers = ['automatic', 'code'];
    statuses = ['draft', 'active', 'paused', 'ended'];

    constructor() {
        super(...arguments);
        this.loadTargets();
    }

    get promotion() {
        return this.args.options.promotion;
    }

    get appliesTo() {
        return this.promotion.applies_to ?? {};
    }

    get bogo() {
        return this.promotion.bogo_config ?? {};
    }

    async loadTargets() {
        this.products = await this.findAll('product', this.appliesTo.products);
        this.categories = await this.findAll('category', this.appliesTo.categories);
        this.excludedProducts = await this.findAll('product', this.appliesTo.exclude_products);
    }

    async findAll(modelName, ids = []) {
        const records = await Promise.all((ids ?? []).map((id) => this.store.findRecord(modelName, id).catch(() => null)));

        return records.filter(Boolean);
    }

    @action setTargets(key, property, models) {
        this[property] = models;
        this.promotion.applies_to = { ...this.appliesTo, [key]: models.map((model) => model.id) };
    }

    @action setBogo(key, { target }) {
        const value = target.value === '' ? null : Number(target.value);
        this.promotion.bogo_config = { ...this.bogo, [key]: value };
    }

    @action setField(property, { target }) {
        this.promotion[property] = target.value;
    }

    @action setNumber(property, { target }) {
        this.promotion[property] = target.value === '' ? null : Number(target.value);
    }

    @action setMoney(property, value) {
        this.promotion[property] = value === 0 ? null : value;
    }
}
