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
        storeList.className = 'px-1';
        storeList.style.maxHeight = '18rem';
        storeList.style.overflowY = 'auto';

        if (this.hasStores) {
            this.stores.forEach((store) => {
                storeList.appendChild(this.createMenuItem(store?.name || '-', () => this.onSwitchStore(store)));
            });
        } else {
            const emptyItem = document.createElement('div');
            emptyItem.className = 'next-dd-item';
            emptyItem.setAttribute('role', 'menuitem');
            emptyItem.textContent = this.intl.t('storefront.component.store-selector.no-stores');
            storeList.appendChild(emptyItem);
        }

        const footer = document.createElement('div');
        footer.className = 'px-1';

        const separator = document.createElement('div');
        separator.className = 'next-dd-menu-seperator';

        const footerGroup = document.createElement('div');
        footerGroup.setAttribute('role', 'group');
        footerGroup.className = 'px-1';
        footerGroup.appendChild(this.createMenuItem(this.intl.t('storefront.component.store-selector.create-storefront'), () => this.onCreateStore()));

        footer.append(separator, footerGroup);
        menu.append(storeList, footer);

        return menu;
    }

    createMenuItem(label, callback) {
        const item = document.createElement('a');
        item.href = 'javascript:;';
        item.className = 'next-dd-item';
        item.setAttribute('role', 'menuitem');
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
}
