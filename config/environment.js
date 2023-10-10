/* eslint-env node */
'use strict';
const { name } = require('../package');

module.exports = function (environment) {
    let ENV = {
        modulePrefix: name,
        environment,

        defaultValues: {
            categoryImage: getenv('DEFAULT_CATEGORY_IMAGE', 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/images/fallback-placeholder-1.png'),
            placeholderImage: getenv('DEFAULT_PLACEHOLDER_IMAGE', 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/images/fallback-placeholder-2.png'),
        },
    };

    return ENV;
};

function getenv(variable, defaultValue = null) {
    return process.env[variable] !== undefined ? process.env[variable] : defaultValue;
}
