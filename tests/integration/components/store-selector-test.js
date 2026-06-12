import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, triggerEvent, triggerKeyEvent } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | store-selector', function (hooks) {
    setupRenderingTest(hooks);

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
});
