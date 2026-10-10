import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * The context switcher at the top of the sidebar: stores and networks as peers. The
 * chosen one owns the navigation, the dashboard and the data scope.
 *
 * The menu is built in the DOM and positioned fixed so the sidebar's overflow clipping
 * cannot cut it off.
 */
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

    get networks() {
        return Array.from(this.args.networks ?? []);
    }

    get hasStores() {
        return this.stores.length > 0;
    }

    get hasNetworks() {
        return this.networks.length > 0;
    }

    /**
     * The networks group appears once there is something to put in it: a user with one
     * store and no networks sees the plain store menu.
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

    get badge() {
        const badge = Number(this.args.badge ?? 0);

        return Number.isFinite(badge) && badge > 0 ? badge : null;
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
        const t = (key) => this.intl.t(`storefront.component.store-selector.${key}`);
        const menu = document.createElement('div');
        menu.setAttribute('role', 'menu');
        menu.className = 'store-selector-dropdown-menu storefront-switcher-menu';
        menu.setAttribute('data-theme', document.body.dataset.theme ?? 'light');

        const storeList = this.createGroup('stores', this.showNetworks ? t('stores') : null);

        if (this.hasStores) {
            this.stores.forEach((store) => {
                storeList.appendChild(
                    this.createMenuItem({
                        label: store?.name || '—',
                        meta: store?.currency ?? null,
                        icon: 'store',
                        tone: 'store',
                        isActive: !this.isNetworkContext && store?.id === this.args.activeStore?.id,
                        onClick: () => this.onSwitchStore(store),
                    })
                );
            });
        } else {
            storeList.appendChild(this.createEmptyItem(t('no-stores')));
        }

        menu.appendChild(storeList);

        if (this.showNetworks) {
            const networkList = this.createGroup('networks', t('networks'));

            if (this.hasNetworks) {
                this.networks.forEach((network) => {
                    const storesCount = Number(network?.stores_count);
                    const count = Number.isFinite(storesCount) && storesCount > 0 ? storesCount : network?.stores?.length > 0 ? network.stores.length : null;

                    networkList.appendChild(
                        this.createMenuItem({
                            label: network?.name || '—',
                            meta: count === null ? null : this.intl.t('storefront.networks.card.stores-count', { count }),
                            icon: 'network-wired',
                            tone: 'network',
                            isActive: this.isNetworkContext && network?.id === this.args.activeNetwork?.id,
                            onClick: () => this.onSwitchNetwork(network),
                        })
                    );
                });
            } else {
                networkList.appendChild(this.createEmptyItem(t('no-networks')));
            }

            menu.appendChild(networkList);
        }

        const footer = this.createGroup('actions', null);
        footer.classList.add('storefront-switcher-menu__footer');

        if (typeof this.args.onCreateStore === 'function') {
            footer.appendChild(this.createMenuItem({ label: t('new-store'), icon: 'plus', tone: 'muted', onClick: () => this.onCreateStore() }));
        }

        if (typeof this.args.onCreateNetwork === 'function') {
            footer.appendChild(this.createMenuItem({ label: t('new-network'), icon: 'plus', tone: 'muted', onClick: () => this.onCreateNetwork() }));
        }

        if (footer.childElementCount) {
            menu.appendChild(footer);
        }

        return menu;
    }

    createGroup(name, heading) {
        const group = document.createElement('div');
        group.setAttribute('role', 'group');
        group.setAttribute(`data-test-store-selector-${name}`, '');
        group.className = 'storefront-switcher-menu__group';

        if (heading) {
            const head = document.createElement('div');
            head.className = 'storefront-switcher-menu__head';
            head.setAttribute('role', 'presentation');
            head.textContent = heading;
            group.appendChild(head);
        }

        return group;
    }

    createMenuItem({ label, meta = null, icon, tone = 'store', isActive = false, onClick }) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = `storefront-switcher-menu__item${isActive ? ' is-active' : ''}`;
        item.setAttribute('role', 'menuitem');

        if (isActive) {
            item.setAttribute('aria-current', 'true');
        }

        const tile = document.createElement('span');
        tile.className = `storefront-switcher-tile storefront-switcher-tile--${tone}`;
        tile.innerHTML = this.iconMarkup(icon);

        const text = document.createElement('span');
        text.className = 'storefront-switcher-menu__label';
        text.textContent = label;

        item.append(tile, text);

        if (meta) {
            const metaElement = document.createElement('span');
            metaElement.className = 'storefront-switcher-menu__meta';
            metaElement.textContent = meta;
            item.appendChild(metaElement);
        }

        if (isActive) {
            const check = document.createElement('span');
            check.className = 'storefront-switcher-menu__check';
            check.innerHTML = this.iconMarkup('check');
            item.appendChild(check);
        }

        item.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            onClick();
        });

        return item;
    }

    createEmptyItem(text) {
        const item = document.createElement('div');
        item.className = 'storefront-switcher-menu__empty';
        item.setAttribute('role', 'presentation');
        item.textContent = text;

        return item;
    }

    /**
     * Inline SVG for the few icons the menu needs; the menu lives outside the component tree,
     * so it cannot use the FaIcon component.
     */
    iconMarkup(name) {
        const paths = {
            store: '<path d="M3 9l1-5h16l1 5M3 9v11h18V9M3 9h18M9 20v-6h6v6"/>',
            'network-wired': '<rect x="9" y="2" width="6" height="5" rx="1"/><rect x="2" y="17" width="6" height="5" rx="1"/><rect x="16" y="17" width="6" height="5" rx="1"/><path d="M12 7v5M5 17v-3h14v3"/>',
            plus: '<path d="M12 5v14M5 12h14"/>',
            check: '<path d="M5 12l5 5L20 7"/>',
        };

        return `<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] ?? ''}</svg>`;
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
        const width = Math.max(rect.width, 260);
        const maxLeft = window.innerWidth - width - viewportPadding;
        const left = Math.max(viewportPadding, Math.min(rect.left, maxLeft));
        let top = rect.bottom + 6;

        this.menuElement.style.width = `${width}px`;

        const menuHeight = this.menuElement.offsetHeight;
        if (top + menuHeight > window.innerHeight - viewportPadding && rect.top - menuHeight - 6 > viewportPadding) {
            top = rect.top - menuHeight - 6;
        }

        this.menuElement.style.left = `${left}px`;
        this.menuElement.style.top = `${Math.max(viewportPadding, top)}px`;
    };

    @action onSwitchStore(store) {
        this.args.onSwitchStore?.(store);
        this.close();
    }

    @action onSwitchNetwork(network) {
        this.args.onSwitchNetwork?.(network);
        this.close();
    }

    @action onCreateStore() {
        this.args.onCreateStore?.();
        this.close();
    }

    @action onCreateNetwork() {
        this.args.onCreateNetwork?.();
        this.close();
    }
}
