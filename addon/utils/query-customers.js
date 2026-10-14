/**
 * Customers of a store or network, from Storefront's own endpoint.
 *
 * The `customer` model's adapter comes from Fleet-Ops and queries `int/v1/query/customers`,
 * which lists vendors next to contacts and ignores the store, network, sort and page params.
 * Storefront's endpoint returns the people who ordered from the store or through the network,
 * paginated, with their order count and photo.
 *
 * @param {Object} services `{ fetch, store }`
 * @param {Object} params query params; blank values are dropped
 * @return {Promise<Array>} customer records with the pagination `meta` attached
 */
export default async function queryCustomers({ fetch, store }, params = {}) {
    const query = Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''));
    const response = await fetch.get('customers', query, { namespace: 'storefront/int/v1' });
    const records = (response?.customers ?? []).map((customer) => store.push(store.normalize('customer', customer)));

    records.meta = response?.meta ?? {};

    return records;
}
