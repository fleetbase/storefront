/**
 * A contact record by its uuid or public id, with its saved places.
 *
 * The API answers a public id with the contact keyed by its uuid, and ember-data then re-keys the
 * record: asking for the same public id again in the session throws. A contact already loaded is
 * fetched again by its uuid.
 *
 * @param {Store} store
 * @param {String} key the contact's uuid or public id
 * @param {Object} options findRecord options
 * @return {Promise<ContactModel>}
 */
export default function findContact(store, key, options = {}) {
    const loaded = store.peekAll('contact').find((contact) => contact.id === key || contact.public_id === key);

    return Promise.resolve().then(() => store.findRecord('contact', loaded?.id ?? key, options));
}
