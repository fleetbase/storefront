import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class StoreSelectorComponent extends Component {
    @service intl;
    @tracked isOpen = false;
    triggerElement;
    menuElement;
    teardownListeners = [];
    animationFrame;

    get stores() {
        return Array.from(this.args.stores ?? []);
    }

    get hasStores() {
        return this.stores.length > 0;
    }

    get networks() {
        return Array.from(this.args.networks ?? []);
    }

    get hasNetworks() {
        return this.networks.length > 0;
    }

    /**
     * The networks group only appears once there is something to show in it: a user with
     * one store and no networks sees the plain store menu.
     */
    get showNetworks() {
        return this.hasNetworks || typeof this.args.onCreateNetwork === 'function';
    }

    get isNetworkContext() {
        return Boolean(this.args.activeNetwork);
    }

    get activeContext() {
        return this.args.activeNetwork ?? this.args.activeStore;
    }

    get contextLabel() {
        return this.intl.t(`storefront.component.store-selector.${this.isNetworkContext ? 'network' : 'store'}`);
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.close();
    }

    @action setupTrigger(element) {
        this.triggerElement = element;
    }

    @action toggle(event) {
        event?.preventDefault?.();
        event?.stopPropagation?.();

        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        if (!this.triggerElement || this.isOpen) {
            return;
        }

        this.isOpen = true;
        this.menuElement = this.createMenuElement();
        document.body.appendChild(this.menuElement);
        this.positionMenu();
        this.addListeners();
    }

    @action close() {
        if (this.animationFrame) {
            cancelAnimationFrame(this.animationFrame);
            this.animationFrame = undefined;
        }

        this.removeListeners();
        this.menuElement?.remove();
        this.menuElement = undefined;
        this.isOpen = false;
    }

    createMenuElement() {
        const menu = document.createElement('div');
        menu.setAttribute('role', 'menu');
        menu.className = 'store-selector-dropdown-menu next-dd-menu py-1';
        menu.style.position = 'fixed';
        menu.style.zIndex = '900';
        menu.style.margin = '0';
        menu.style.height = 'auto';
        menu.style.minHeight = '0';
        menu.style.maxHeight = 'calc(100vh - 16px)';
        menu.style.overflowX = 'hidden';
        menu.style.overflowY = 'visible';

        const storeList = document.createElement('div');
        storeList.setAttribute('role', 'group');
        storeList.setAttribute('data-test-store-selector-stores', '');
        storeList.className = 'px-1';
        storeList.style.maxHeight = '18rem';
        storeList.style.overflowY = 'auto';

        if (this.showNetworks) {
            storeList.appendChild(this.createGroupLabel(this.intl.t('storefront.component.store-selector.stores')));
        }

        if (this.hasStores) {
            this.stores.forEach((store) => {
                const isActive = !this.isNetworkContext && store?.id === this.args.activeStore?.id;
                storeList.appendChild(this.createMenuItem(store?.name || '-', () => this.onSwitchStore(store), { isActive }));
            });
        } else {
            storeList.appendChild(this.createEmptyItem(this.intl.t('storefront.component.store-selector.no-stores')));
        }

        menu.append(storeList);

        if (this.showNetworks) {
            const networkList = document.createElement('div');
            networkList.setAttribute('role', 'group');
            networkList.setAttribute('data-test-store-selector-networks', '');
            networkList.className = 'px-1';
            networkList.style.maxHeight = '12rem';
            networkList.style.overflowY = 'auto';
            networkList.appendChild(this.createSeparator());
            networkList.appendChild(this.createGroupLabel(this.intl.t('storefront.component.store-selector.networks')));

            if (this.hasNetworks) {
                this.networks.forEach((network) => {
                    const isActive = network?.id === this.args.activeNetwork?.id;
                    networkList.appendChild(this.createMenuItem(network?.name || '-', () => this.onSwitchNetwork(network), { isActive }));
                });
            } else {
                networkList.appendChild(this.createEmptyItem(this.intl.t('storefront.component.store-selector.no-networks')));
            }

            menu.append(networkList);
        }

        const footer = document.createElement('div');
        footer.className = 'px-1';

        const footerGroup = document.createElement('div');
        footerGroup.setAttribute('role', 'group');
        footerGroup.setAttribute('data-test-store-selector-actions', '');
        footerGroup.className = 'px-1';
        footerGroup.appendChild(this.createMenuItem(this.intl.t('storefront.component.store-selector.create-storefront'), () => this.onCreateStore()));

        if (typeof this.args.onCreateNetwork === 'function') {
            footerGroup.appendChild(this.createMenuItem(this.intl.t('storefront.component.store-selector.create-network'), () => this.onCreateNetwork()));
        }

        footer.append(this.createSeparator(), footerGroup);
        menu.append(footer);

        return menu;
    }

    createSeparator() {
        const separator = document.createElement('div');
        separator.className = 'next-dd-menu-seperator';

        return separator;
    }

    createGroupLabel(text) {
        const label = document.createElement('div');
        label.className = 'storefront-context-switcher__label';
        label.setAttribute('role', 'presentation');
        label.textContent = text;

        return label;
    }

    createEmptyItem(text) {
        const emptyItem = document.createElement('div');
        emptyItem.className = 'next-dd-item storefront-context-switcher__empty';
        emptyItem.setAttribute('role', 'menuitem');
        emptyItem.setAttribute('aria-disabled', 'true');
        emptyItem.textContent = text;

        return emptyItem;
    }

    createMenuItem(label, callback, { isActive = false } = {}) {
        const item = document.createElement('a');
        item.href = 'javascript:;';
        item.className = `next-dd-item${isActive ? ' storefront-context-switcher__item--active' : ''}`;
        item.setAttribute('role', 'menuitem');
        if (isActive) {
            item.setAttribute('aria-current', 'true');
        }
        item.textContent = label;
        item.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            callback();
        });

        return item;
    }

    addListeners() {
        this.addManagedListener(document, 'mousedown', this.handleDocumentMouseDown, true);
        this.addManagedListener(document, 'keydown', this.handleDocumentKeydown);
        this.addManagedListener(window, 'resize', this.schedulePositionMenu);
        this.addManagedListener(window, 'scroll', this.schedulePositionMenu, true);
    }

    addManagedListener(target, eventName, handler, options = false) {
        target.addEventListener(eventName, handler, options);
        this.teardownListeners.push(() => target.removeEventListener(eventName, handler, options));
    }

    removeListeners() {
        this.teardownListeners.forEach((teardown) => teardown());
        this.teardownListeners = [];
    }

    handleDocumentMouseDown = (event) => {
        if (this.triggerElement?.contains(event.target) || this.menuElement?.contains(event.target)) {
            return;
        }

        this.close();
    };

    handleDocumentKeydown = (event) => {
        if (event.key === 'Escape') {
            this.close();
        }
    };

    schedulePositionMenu = () => {
        if (this.animationFrame) {
            cancelAnimationFrame(this.animationFrame);
        }

        this.animationFrame = requestAnimationFrame(() => {
            this.animationFrame = undefined;
            this.positionMenu();
        });
    };

    positionMenu = () => {
        if (!this.triggerElement || !this.menuElement) {
            return;
        }

        const rect = this.triggerElement.getBoundingClientRect();
        const viewportPadding = 8;
        const width = Math.max(rect.width, 220);
        const maxLeft = window.innerWidth - width - viewportPadding;
        const left = Math.max(viewportPadding, Math.min(rect.left, maxLeft));
        let top = rect.bottom + 4;

        this.menuElement.style.width = `${width}px`;

        const menuHeight = this.menuElement.offsetHeight;
        if (top + menuHeight > window.innerHeight - viewportPadding && rect.top - menuHeight - 4 > viewportPadding) {
            top = rect.top - menuHeight - 4;
        }

        this.menuElement.style.left = `${left}px`;
        this.menuElement.style.top = `${Math.max(viewportPadding, top)}px`;
    };

    @action onSwitchStore(store) {
        const { onSwitchStore } = this.args;

        if (typeof onSwitchStore === 'function') {
            onSwitchStore(store);
        }

        this.close();
    }

    @action onCreateStore() {
        const { onCreateStore } = this.args;

        if (typeof onCreateStore === 'function') {
            onCreateStore();
        }

        this.close();
    }

    @action onSwitchNetwork(network) {
        const { onSwitchNetwork } = this.args;

        if (typeof onSwitchNetwork === 'function') {
            onSwitchNetwork(network);
        }

        this.close();
    }

    @action onCreateNetwork() {
        const { onCreateNetwork } = this.args;

        if (typeof onCreateNetwork === 'function') {
            onCreateNetwork();
        }

        this.close();
    }
}
