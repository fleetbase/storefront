/* eslint-env node */
'use strict';
const { name, fleetbase } = require('../package');

module.exports = function (environment) {
    let ENV = {
        modulePrefix: name,
        environment,
        mountedEngineRoutePrefix: getMountedEngineRoutePrefix(),

        defaultValues: {
            categoryImage: getenv('DEFAULT_CATEGORY_IMAGE', 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/images/fallback-placeholder-1.png'),
            placeholderImage: getenv('DEFAULT_PLACEHOLDER_IMAGE', 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/images/fallback-placeholder-2.png'),
        },
    };

    return ENV;
};

function getMountedEngineRoutePrefix() {
    let mountedEngineRoutePrefix = 'storefront';
    if (fleetbase && typeof fleetbase.route === 'string') {
        mountedEngineRoutePrefix = fleetbase.route;
    }

    return `console.${mountedEngineRoutePrefix}.`;
}

function getenv(variable, defaultValue = null) {
    return process.env[variable] !== undefined ? process.env[variable] : defaultValue;
}
