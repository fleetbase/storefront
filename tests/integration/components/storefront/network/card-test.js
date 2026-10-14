import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | storefront/network/card', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('it renders the network, its member count, its logo and its actions', async function (assert) {
        const opened = [];
        this.set('network', {
            name: 'Fleetbase Marketplace',
            currency: 'SGD',
            online: true,
            stores_count: 5,
            logo_url: 'https://cdn.test/logo.png',
            backdrop_url: 'https://cdn.test/backdrop.png',
        });
        this.set('open', (network) => opened.push(network));
        this.set('noop', () => {});

        await render(hbs`<Storefront::Network::Card @network={{this.network}} @onOpen={{this.open}} @onInvite={{this.noop}} @onDelete={{this.noop}} />`);

        assert.dom('[data-test-network-name]').hasText('Fleetbase Marketplace');
        assert.dom('[data-test-network-indicator]').hasClass('text-green-500');
        assert.dom('[data-test-storefront-network-card]').includesText('SGD · 5 stores');
        assert.dom('[data-test-network-logo]').hasAttribute('src', 'https://cdn.test/logo.png');
        assert.dom('[data-test-network-status]').includesText('Online');
        assert.dom('[data-test-network-open]').exists();
        assert.dom('[data-test-network-invite]').exists();
        assert.dom('[data-test-network-delete]').exists();

        await click('[data-test-network-name]');
        assert.strictEqual(opened[0], this.network);
    });

    test('an offline network counts its loaded stores when no count is given', async function (assert) {
        this.set('network', { name: 'Night market', online: false, stores: [{}, {}] });

        await render(hbs`<Storefront::Network::Card @network={{this.network}} />`);

        assert.dom('[data-test-network-indicator]').hasClass('text-gray-400');
        assert.dom('[data-test-network-status]').includesText('Offline');
        assert.dom('[data-test-storefront-network-card]').includesText('2 stores');
    });
});
