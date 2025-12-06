'use strict';
const { buildEngine } = require('ember-engines/lib/engine-addon');
const { name } = require('./package');
const Funnel = require('broccoli-funnel');
const MergeTrees = require('broccoli-merge-trees');
const path = require('path');

module.exports = buildEngine({
    name,

    lazyLoading: {
        enabled: true,
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
