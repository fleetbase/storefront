import Service from '@ember/service';
import EmberObject from '@ember/object';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import { render, click, fillIn, typeIn } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class AbilitiesStubService extends Service {
    can() {
        return true;
    }
    cannot() {
        return false;
    }
}

class NotificationsStubService extends Service {
    success() {}
    warning() {}
    serverError(error) {
        throw error;
    }
}

module('Integration | Component | storefront/promotion/builder', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        this.owner.register('service:abilities', AbilitiesStubService);
        this.owner.register('service:notifications', NotificationsStubService);
    });

    test('typing in a field keeps the field: the step is not rebuilt on every keystroke', async function (assert) {
        this.set('promotion', EmberObject.create({ isNew: true, type: 'percentage', name: '', value: null, applies_to: {}, bogo_config: {} }));
        this.set('noop', () => {});

        await render(hbs`<Storefront::Promotion::Builder @promotion={{this.promotion}} @currency="USD" @storeId="store_1" @onClose={{this.noop}} @onSaved={{this.noop}} />`);

        // Each step's summary reads the form, so every keystroke changes the steps list.
        const name = this.element.querySelector('[data-test-builder-step="what"] .storefront-builder__step-body input[type="text"]');
        assert.ok(name, 'the name field is on the first step');

        await typeIn(name, 'Sum');
        assert.true(name.isConnected, 'the same input is still on the page after each keystroke');
        assert.strictEqual(this.promotion.name, 'Sum');

        const percent = this.element.querySelector('[data-test-builder-step="what"] input[type="number"]');
        await fillIn(percent, '15');
        assert.true(percent.isConnected, 'the percent field survives a change too');
        assert.true(name.isConnected);
    });

    test('saving keeps the chosen status: the click on Save is not taken for one', async function (assert) {
        const saved = [];
        this.set(
            'promotion',
            EmberObject.create({
                isNew: true,
                type: 'percentage',
                name: 'Summer',
                value: 10,
                status: 'active',
                applies_to: {},
                bogo_config: {},
                save() {
                    saved.push(this.status);
                    return Promise.resolve(this);
                },
            })
        );
        this.set('noop', () => {});

        await render(hbs`<Storefront::Promotion::Builder @promotion={{this.promotion}} @step="review" @currency="USD" @storeId="store_1" @onClose={{this.noop}} @onSaved={{this.noop}} />`);
        await click('[data-test-builder-save]');

        assert.deepEqual(saved, ['active'], 'the status stays a status');
    });
});
