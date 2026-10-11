import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click, fillIn } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class FetchStubService extends Service {
    calls = [];
    get(url) {
        this.calls.push(['get', url]);
        return Promise.resolve({ count: 42 });
    }
    post(url) {
        this.calls.push(['post', url]);
        return Promise.resolve({});
    }
}

class NotificationsStubService extends Service {
    messages = [];
    warning(message) {
        this.messages.push(['warning', message]);
    }
    success(message) {
        this.messages.push(['success', message]);
    }
    serverError(error) {
        this.messages.push(['error', error?.message]);
    }
}

function makeCampaign(overrides = {}) {
    return {
        isNew: true,
        status: 'draft',
        isEditable: true,
        hasDirtyAttributes: true,
        channels: ['push', 'inbox'],
        name: '',
        title: '',
        body: '',
        owner_uuid: 'store_1',
        saved: 0,
        save() {
            this.saved++;
            this.isNew = false;
            this.id = 'campaign_1';
            return Promise.resolve(this);
        },
        rollbackAttributes() {
            this.rolledBack = true;
        },
        ...overrides,
    };
}

module('Integration | Component | storefront/promotion/campaign-panel', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:notifications', NotificationsStubService);
    });

    test('a new campaign opens as a draft form with the save bar and switches to schedule', async function (assert) {
        this.set('campaign', makeCampaign());
        this.set('close', () => {});

        await render(hbs`<Storefront::Promotion::CampaignPanel @campaign={{this.campaign}} @onClose={{this.close}} />`);

        assert.dom('[data-test-campaign-panel]').exists();
        assert.dom('.next-content-overlay-panel-header, .overlay-panel-header, [class*="overlay"]').includesText('New campaign');
        assert.dom('[data-test-campaign-panel-save]').hasText('Save draft');
        assert.dom('[data-test-campaign-panel-save-bar]').hasClass('is-dirty');
        assert.dom('[data-test-campaign-send-at]').doesNotExist();

        await click('[data-test-campaign-when-option="schedule"]');
        assert.dom('[data-test-campaign-panel-save]').hasText('Schedule');
        assert.dom('[data-test-campaign-send-at]').exists('the send-at input appears for a scheduled campaign');

        await click('[data-test-campaign-when-option="now"]');
        assert.dom('[data-test-campaign-panel-save]').hasText('Send now');
    });

    test('saving validates, saves, sends when asked and closes', async function (assert) {
        const campaign = makeCampaign();
        const closed = [];
        const saved = [];
        this.set('campaign', campaign);
        this.set('close', () => closed.push(true));
        this.set('onSaved', (record) => saved.push(record));

        await render(hbs`<Storefront::Promotion::CampaignPanel @campaign={{this.campaign}} @when="now" @onClose={{this.close}} @onSaved={{this.onSaved}} />`);

        await click('[data-test-campaign-panel-save]');
        const notifications = this.owner.lookup('service:notifications');
        assert.strictEqual(campaign.saved, 0, 'nothing is saved without the required fields');
        assert.deepEqual(notifications.messages[0][0], 'warning');

        await fillIn('[data-test-campaign-name] input', 'Weekend push');
        campaign.title = 'Weekend 15% off';
        campaign.body = 'Use WKND15 this weekend.';
        await click('[data-test-campaign-panel-save]');

        const fetch = this.owner.lookup('service:fetch');
        assert.strictEqual(campaign.saved, 1, 'the campaign is saved');
        assert.deepEqual(fetch.calls.at(-1), ['post', 'campaigns/campaign_1/send'], 'send now posts to the send endpoint');
        assert.strictEqual(saved.length, 1);
        assert.strictEqual(closed.length, 1);
    });

    test('a sent campaign is read only with a done button, and cancel rolls back', async function (assert) {
        const campaign = makeCampaign({ isNew: false, id: 'campaign_9', status: 'sent', isEditable: false, hasDirtyAttributes: false, name: 'Launch' });
        const closed = [];
        this.set('campaign', campaign);
        this.set('close', () => closed.push(true));

        await render(hbs`<Storefront::Promotion::CampaignPanel @campaign={{this.campaign}} @onClose={{this.close}} />`);

        assert.dom('[data-test-campaign-panel] fieldset').hasAttribute('disabled');
        assert.dom('[data-test-campaign-panel-save]').doesNotExist();
        assert.dom('[data-test-campaign-panel-cancel]').hasText('Done');
        assert.dom('[data-test-campaign-audience-count]').includesText('42');

        await click('[data-test-campaign-panel-cancel]');
        assert.true(campaign.rolledBack);
        assert.strictEqual(closed.length, 1);
    });
});
