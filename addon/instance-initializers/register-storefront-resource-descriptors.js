import { get } from '@ember/object';

/**
 * Registers the Storefront resources (store, network) with the shared resource
 * identity registry so they render as identity cells, pills and summaries with
 * the same components every other resource uses. Runs for the host and again for
 * the engine; only fills in keys nobody has registered.
 */
export function initialize(owner) {
    let registry;

    try {
        registry = owner.lookup('service:resource-registry');
    } catch {
        registry = null;
    }

    if (!registry || typeof registry.registerDescriptors !== 'function') {
        return;
    }

    const missing = buildStorefrontResourceDescriptors(owner).filter((descriptor) => !isRegistered(registry, descriptor.key));

    if (missing.length) {
        registry.registerDescriptors(missing);
    }
}

export function buildStorefrontResourceDescriptors(owner) {
    const first = (record, ...paths) => {
        for (const path of paths) {
            const value = record ? get(record, path) : undefined;
            if (value !== undefined && value !== null && value !== '') {
                return value;
            }
        }

        return null;
    };
    const online = (record) => (first(record, 'online') === null ? null : Boolean(get(record, 'online')));
    const presence = (record) => (online(record) === null ? null : online(record) ? 'online' : 'offline');
    const fact = (label, value, extra = {}) => (value ? { label, value, ...extra } : null);
    const transitionTo = (route, record) => {
        try {
            const hostRouter = owner.lookup('service:host-router');
            const publicId = get(record, 'public_id');

            if (hostRouter && publicId) {
                hostRouter.transitionTo(route, publicId);
                return true;
            }
        } catch {
            // no host router outside the console
        }

        return false;
    };

    return [
        {
            key: 'store',
            labelKey: 'storefront.common.store',
            icon: 'store',
            modelNames: ['store'],
            aliases: ['storefront-store'],
            polymorphicTypes: ['storefront:store', 'Fleetbase\\Storefront\\Models\\Store'],
            permission: 'storefront view store',
            statusTones: {
                online: 'text-green-500',
                offline: 'text-gray-400',
            },
            title: (store) => first(store, 'name', 'public_id'),
            identifier: (store) => first(store, 'currency', 'public_id'),
            image: (store) => first(store, 'logo_url'),
            online,
            status: presence,
            badges: (store) => [first(store, 'currency') ? { key: 'currency', icon: 'coins', label: first(store, 'currency') } : null].filter(Boolean),
            selectDetails: (store) => [first(store, 'currency'), first(store, 'email')],
            facts: (store) => [
                fact('email', first(store, 'email')),
                fact('phone', first(store, 'phone')),
                fact('website', first(store, 'website')),
                fact('currency', first(store, 'currency')),
                fact('timezone', first(store, 'timezone')),
            ],
            canOpen: () => false,
        },
        {
            key: 'network',
            labelKey: 'storefront.common.network',
            icon: 'network-wired',
            modelNames: ['network'],
            aliases: ['storefront-network'],
            polymorphicTypes: ['storefront:network', 'Fleetbase\\Storefront\\Models\\Network'],
            permission: 'storefront view network',
            statusTones: {
                online: 'text-green-500',
                offline: 'text-gray-400',
            },
            title: (network) => first(network, 'name', 'public_id'),
            identifier: (network) => first(network, 'currency', 'public_id'),
            image: (network) => first(network, 'logo_url'),
            online,
            status: presence,
            badges: (network) => [first(network, 'stores_count') !== null ? { key: 'stores', icon: 'store', label: `${first(network, 'stores_count')} stores` } : null].filter(Boolean),
            selectDetails: (network) => [first(network, 'currency'), first(network, 'email')],
            facts: (network) => [fact('email', first(network, 'email')), fact('phone', first(network, 'phone')), fact('website', first(network, 'website')), fact('currency', first(network, 'currency'))],
            canOpen: () => true,
            open: (network) => transitionTo('console.storefront.networks.index.network.index', network),
        },
    ];
}

function isRegistered(registry, key) {
    try {
        return Boolean(typeof registry.getDescriptor === 'function' ? registry.getDescriptor(key) : registry.descriptors?.some?.((descriptor) => descriptor.key === key));
    } catch {
        return false;
    }
}

export default {
    name: 'register-storefront-resource-descriptors',
    initialize,
};
