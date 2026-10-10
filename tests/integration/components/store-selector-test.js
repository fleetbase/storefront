import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, triggerEvent, triggerKeyEvent } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

module('Integration | Component | store-selector', function (hooks) {
    setupRenderingTest(hooks);
    // The trigger reads a translation while rendering, so intl must exist before the first render.
    setupIntl(hooks, 'en-us');

    hooks.afterEach(function () {
        document.querySelectorAll('.store-selector-dropdown-menu').forEach((menu) => menu.remove());
    });

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<StoreSelector />`);

        assert.dom(this.element).hasText('');

        // Template block usage:
        await render(hbs`
      <StoreSelector>
        template block text
      </StoreSelector>
    `);

        assert.dom(this.element).hasText('template block text');
    });

    test('it renders a fixed dropdown outside the component tree without BasicDropdown', async function (assert) {
        this.set('activeStore', { id: 'store_1', name: 'Fleetbase Market' });
        this.set('stores', [
            { id: 'store_1', name: 'Fleetbase Market' },
            { id: 'store_2', name: 'Second Market' },
        ]);
        this.set('noop', () => {});

        await render(hbs`
            <StoreSelector
                @stores={{this.stores}}
                @activeStore={{this.activeStore}}
                @onCreateStore={{this.noop}}
                @onSwitchStore={{this.noop}}
            />
        `);
        await click('button');

        const dropdownContent = document.body.querySelector('.store-selector-dropdown-menu');

        assert.ok(dropdownContent, 'dropdown content renders when opened');
        assert.false(this.element.contains(dropdownContent), 'dropdown content renders outside the sidebar-clipped component tree');
        assert.strictEqual(dropdownContent.style.position, 'fixed', 'dropdown uses fixed positioning');
        assert.ok(dropdownContent.querySelector('[role="group"]'), 'stores render as a group');
        assert.dom('.ember-basic-dropdown-content').doesNotExist('does not use BasicDropdown content');
    });

    test('it switches stores and closes the dropdown', async function (assert) {
        assert.expect(3);

        this.set('activeStore', { id: 'store_1', name: 'Fleetbase Market' });
        this.set('stores', [
            { id: 'store_1', name: 'Fleetbase Market' },
            { id: 'store_2', name: 'Second Market' },
        ]);
        this.set('createStore', () => {});
        this.set('switchStore', (store) => {
            assert.strictEqual(store.id, 'store_2', 'passes the selected store');
        });

        await render(hbs`
            <StoreSelector
                @stores={{this.stores}}
                @activeStore={{this.activeStore}}
                @onCreateStore={{this.createStore}}
                @onSwitchStore={{this.switchStore}}
            />
        `);
        await click('button');
        assert.dom(document.body.querySelector('.store-selector-dropdown-menu')).exists('dropdown opens');
        await click(document.body.querySelectorAll('.store-selector-dropdown-menu .storefront-switcher-menu__item')[1]);

        assert.dom(document.body.querySelector('.store-selector-dropdown-menu')).doesNotExist('dropdown closes after switching stores');
    });

    test('it creates a store and closes the dropdown', async function (assert) {
        assert.expect(3);

        this.set('activeStore', { id: 'store_1', name: 'Fleetbase Market' });
        this.set('stores', [{ id: 'store_1', name: 'Fleetbase Market' }]);
        this.set('createStore', () => {
            assert.ok(true, 'calls create store action');
        });
        this.set('switchStore', () => {});

        await render(hbs`
            <StoreSelector
                @stores={{this.stores}}
                @activeStore={{this.activeStore}}
                @onCreateStore={{this.createStore}}
                @onSwitchStore={{this.switchStore}}
            />
        `);
        await click('button');
        assert.dom(document.body.querySelector('.store-selector-dropdown-menu')).exists('dropdown opens');
        await click(document.body.querySelector('[data-test-store-selector-actions] .storefront-switcher-menu__item'));

        assert.dom(document.body.querySelector('.store-selector-dropdown-menu')).doesNotExist('dropdown closes after create action');
    });

    test('it closes on escape and outside click', async function (assert) {
        this.set('activeStore', { id: 'store_1', name: 'Fleetbase Market' });
        this.set('stores', [{ id: 'store_1', name: 'Fleetbase Market' }]);
        this.set('noop', () => {});

        await render(hbs`
            <StoreSelector
                @stores={{this.stores}}
                @activeStore={{this.activeStore}}
                @onCreateStore={{this.noop}}
                @onSwitchStore={{this.noop}}
            />
        `);
        await click('button');
        assert.dom(document.body.querySelector('.store-selector-dropdown-menu')).exists('dropdown opens');

        await triggerKeyEvent(document, 'keydown', 'Escape');
        assert.dom(document.body.querySelector('.store-selector-dropdown-menu')).doesNotExist('escape closes dropdown');

        await click('button');
        assert.dom(document.body.querySelector('.store-selector-dropdown-menu')).exists('dropdown opens again');

        await triggerEvent(document.body, 'mousedown');
        assert.dom(document.body.querySelector('.store-selector-dropdown-menu')).doesNotExist('outside click closes dropdown');
    });

    test('a user with stores and no networks sees the plain store menu', async function (assert) {
        this.set('activeStore', { id: 'store_1', name: 'Fleetbase Market' });
        this.set('stores', [{ id: 'store_1', name: 'Fleetbase Market' }]);
        this.set('noop', () => {});

        await render(hbs`<StoreSelector @stores={{this.stores}} @activeStore={{this.activeStore}} @onCreateStore={{this.noop}} @onSwitchStore={{this.noop}} />`);
        await click('button');

        const menu = document.body.querySelector('.store-selector-dropdown-menu');
        assert.dom('[data-test-store-selector-trigger]').hasAttribute('data-context', 'store');
        assert.dom(menu.querySelector('[data-test-store-selector-networks]')).doesNotExist('no networks group');
        assert.dom(menu.querySelector('.storefront-switcher-menu__head')).doesNotExist('no group labels when there is only one group');
        assert.dom(menu.querySelector('[aria-current="true"]')).hasText('Fleetbase Market', 'the active store is marked');
        assert.strictEqual(menu.querySelectorAll('[data-test-store-selector-actions] .storefront-switcher-menu__item').length, 1, 'only the new store action');
    });

    test('it lists networks as a second group and switches into one', async function (assert) {
        assert.expect(7);

        this.set('activeStore', { id: 'store_1', name: 'Fleetbase Market' });
        this.set('stores', [{ id: 'store_1', name: 'Fleetbase Market' }]);
        this.set('networks', [
            { id: 'network_1', name: 'Downtown Market' },
            { id: 'network_2', name: 'Uptown Market' },
        ]);
        this.set('noop', () => {});
        this.set('switchNetwork', (network) => {
            assert.strictEqual(network.id, 'network_2', 'passes the selected network');
        });

        await render(hbs`
            <StoreSelector
                @stores={{this.stores}}
                @networks={{this.networks}}
                @activeStore={{this.activeStore}}
                @onCreateStore={{this.noop}}
                @onSwitchStore={{this.noop}}
                @onSwitchNetwork={{this.switchNetwork}}
                @onCreateNetwork={{this.noop}}
            />
        `);
        await click('button');

        const menu = document.body.querySelector('.store-selector-dropdown-menu');
        assert.deepEqual(
            Array.from(menu.querySelectorAll('.storefront-switcher-menu__head')).map((label) => label.textContent),
            ['Stores', 'Networks'],
            'both groups are labelled'
        );
        const networkItems = menu.querySelectorAll('[data-test-store-selector-networks] .storefront-switcher-menu__item');
        assert.deepEqual(
            Array.from(networkItems).map((item) => item.textContent),
            ['Downtown Market', 'Uptown Market']
        );
        assert.dom(menu.querySelector('[data-test-store-selector-stores] [aria-current="true"]')).hasText('Fleetbase Market');
        assert.dom(menu.querySelector('[data-test-store-selector-networks] [aria-current="true"]')).doesNotExist('no network is active in the store context');
        assert.strictEqual(menu.querySelectorAll('[data-test-store-selector-actions] .storefront-switcher-menu__item').length, 2, 'new store and new network actions');

        await click(networkItems[1]);
        assert.dom(document.body.querySelector('.store-selector-dropdown-menu')).doesNotExist('dropdown closes after switching networks');
    });

    test('in the network context the trigger shows the network and marks it active', async function (assert) {
        this.set('activeStore', { id: 'store_1', name: 'Fleetbase Market' });
        this.set('activeNetwork', { id: 'network_1', name: 'Downtown Market' });
        this.set('stores', [{ id: 'store_1', name: 'Fleetbase Market' }]);
        this.set('networks', [{ id: 'network_1', name: 'Downtown Market' }]);
        this.set('noop', () => {});

        await render(hbs`
            <StoreSelector
                @stores={{this.stores}}
                @networks={{this.networks}}
                @activeStore={{this.activeStore}}
                @activeNetwork={{this.activeNetwork}}
                @onCreateStore={{this.noop}}
                @onSwitchStore={{this.noop}}
                @onSwitchNetwork={{this.noop}}
            />
        `);

        assert.dom('[data-test-store-selector-trigger]').hasAttribute('data-context', 'network');
        assert.dom('[data-test-store-selector-trigger]').containsText('Downtown Market');
        assert.dom('[data-test-store-selector-trigger]').hasAttribute('title', 'Network: Downtown Market');

        await click('button');
        const menu = document.body.querySelector('.store-selector-dropdown-menu');
        assert.dom(menu.querySelector('[data-test-store-selector-networks] [aria-current="true"]')).hasText('Downtown Market');
        assert.dom(menu.querySelector('[data-test-store-selector-stores] [aria-current="true"]')).doesNotExist('the store is not marked while a network is active');
        assert.dom(menu.querySelector('[data-test-store-selector-actions]').lastElementChild).hasText('New store', 'no new network action without a handler');
    });

    test('it creates a network and closes the dropdown', async function (assert) {
        assert.expect(2);

        this.set('activeStore', { id: 'store_1', name: 'Fleetbase Market' });
        this.set('stores', [{ id: 'store_1', name: 'Fleetbase Market' }]);
        this.set('noop', () => {});
        this.set('createNetwork', () => assert.ok(true, 'calls create network action'));

        await render(
            hbs`<StoreSelector @stores={{this.stores}} @activeStore={{this.activeStore}} @onCreateStore={{this.noop}} @onSwitchStore={{this.noop}} @onCreateNetwork={{this.createNetwork}} />`
        );
        await click('button');

        const menu = document.body.querySelector('.store-selector-dropdown-menu');
        assert.dom(menu.querySelector('[data-test-store-selector-networks] .storefront-switcher-menu__empty')).hasText('No networks yet');
        await click(menu.querySelector('[data-test-store-selector-actions] .storefront-switcher-menu__item:last-child'));
    });
});
