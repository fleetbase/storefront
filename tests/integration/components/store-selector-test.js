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
        assert.strictEqual(dropdownContent.style.height, 'auto', 'dropdown height hugs its content');
        assert.strictEqual(dropdownContent.style.minHeight, '0px', 'dropdown does not inherit full-height menu sizing');
        assert.strictEqual(dropdownContent.querySelector('[role="group"]').style.overflowY, 'auto', 'store list only scrolls when needed');
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
        await click(document.body.querySelectorAll('.store-selector-dropdown-menu .next-dd-item')[1]);

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
        await click(document.body.querySelector('.store-selector-dropdown-menu .px-1:last-child .next-dd-item'));

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
        assert.dom(menu.querySelector('.storefront-context-switcher__label')).doesNotExist('no group labels when there is only one group');
        assert.dom(menu.querySelector('[aria-current="true"]')).hasText('Fleetbase Market', 'the active store is marked');
        assert.strictEqual(menu.querySelectorAll('[data-test-store-selector-actions] .next-dd-item').length, 1, 'only the create store action');
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
            Array.from(menu.querySelectorAll('.storefront-context-switcher__label')).map((label) => label.textContent),
            ['Stores', 'Networks'],
            'both groups are labelled'
        );
        const networkItems = menu.querySelectorAll('[data-test-store-selector-networks] .next-dd-item');
        assert.deepEqual(
            Array.from(networkItems).map((item) => item.textContent),
            ['Downtown Market', 'Uptown Market']
        );
        assert.dom(menu.querySelector('[data-test-store-selector-stores] [aria-current="true"]')).hasText('Fleetbase Market');
        assert.dom(menu.querySelector('[data-test-store-selector-networks] [aria-current="true"]')).doesNotExist('no network is active in the store context');
        assert.strictEqual(menu.querySelectorAll('[data-test-store-selector-actions] .next-dd-item').length, 2, 'create store and create network actions');

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
        assert.dom('[data-test-store-selector-trigger]').hasText('Downtown Market');
        assert.dom('[data-test-store-selector-trigger]').hasAttribute('title', 'Network: Downtown Market');

        await click('button');
        const menu = document.body.querySelector('.store-selector-dropdown-menu');
        assert.dom(menu.querySelector('[data-test-store-selector-networks] [aria-current="true"]')).hasText('Downtown Market');
        assert.dom(menu.querySelector('[data-test-store-selector-stores] [aria-current="true"]')).doesNotExist('the store is not marked while a network is active');
        assert.dom(menu.querySelector('[data-test-store-selector-actions]').lastElementChild).hasText('Create a new', 'no create network action without a handler');
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
        assert.dom(menu.querySelector('[data-test-store-selector-networks] .storefront-context-switcher__empty')).hasText('No networks');
        await click(menu.querySelector('[data-test-store-selector-actions] .next-dd-item:last-child'));
    });
});
