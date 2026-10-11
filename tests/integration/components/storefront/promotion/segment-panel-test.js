import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click, fillIn } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

class FetchStubService extends Service {
    posts = [];
    post(url, body) {
        this.posts.push({ url, body });
        return Promise.resolve({ count: Object.keys(body.rules ?? {}).length ? 7 : 120 });
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

module('Integration | Component | storefront/promotion/segment-panel', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:notifications', NotificationsStubService);
    });

    test('it previews the matching customers, refreshes on a rule change and saves', async function (assert) {
        const segment = {
            isNew: true,
            hasDirtyAttributes: true,
            name: '',
            owner_uuid: 'store_1',
            rules: {},
            saved: 0,
            save() {
                this.saved++;
                return Promise.resolve(this);
            },
            rollbackAttributes() {},
        };
        const closed = [];
        this.set('segment', segment);
        this.set('close', () => closed.push(true));

        await render(hbs`<Storefront::Promotion::SegmentPanel @segment={{this.segment}} @currency="USD" @onClose={{this.close}} />`);

        assert.dom('[data-test-segment-panel]').exists();
        assert.dom('[data-test-segment-matching]').includesText('120 matching customers');
        assert.dom('[data-test-segment-panel-save-bar]').hasClass('is-dirty');

        await fillIn('[data-test-segment-rule="min_orders"]', '3');
        assert.strictEqual(segment.rules.min_orders, 3, 'the rule is written to the segment');
        assert.dom('[data-test-segment-matching]').includesText('7 matching customers');

        await click('[data-test-segment-panel-save]');
        assert.strictEqual(segment.saved, 0, 'a name is required');

        await fillIn('[data-test-segment-name] input', 'Regulars');
        await click('[data-test-segment-panel-save]');
        assert.strictEqual(segment.saved, 1);
        assert.strictEqual(closed.length, 1);
    });
});
