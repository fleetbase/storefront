'use strict';
const { buildEngine } = require('ember-engines/lib/engine-addon');
const { name } = require('./package');
const Funnel = require('broccoli-funnel');
const MergeTrees = require('broccoli-merge-trees');
const path = require('path');

module.exports = buildEngine({
    name,

    // Lazy loading keeps the engine out of the host bundle, but it also keeps the
    // engine's modules out of the dummy app that `ember test` builds, so every test
    // importing `@fleetbase/storefront-engine/*` fails to load. Eager loading is
    // scoped to `ember test` run from this package, so host apps consuming the
    // engine, including their own test builds, keep lazy loading. Addon index
    // files are evaluated before ember-cli assigns EMBER_ENV, hence the argv check.
    lazyLoading: {
        enabled: !(process.argv.includes('test') && process.cwd() === __dirname),
    },

    isDevelopingAddon() {
        return true;
    },

    treeForPublic: function () {
        const publicTree = this._super.treeForPublic.apply(this, arguments);

        const addonTree = [
            new Funnel(path.join(__dirname, 'assets'), {
                destDir: '/',
            }),
        ];

        // Merge the addon tree with the existing tree
        return publicTree ? new MergeTrees([publicTree, ...addonTree], { overwrite: true }) : new MergeTrees([...addonTree], { overwrite: true });
    },
});
