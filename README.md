<p align="center">
    <p align="center">
        <img src="https://camo.githubusercontent.com/870812d4ebe999bd1434db7ffcb27c2f347f1c2c6af1842e59ae8b28855b4b42/68747470733a2f2f666c622d6173736574732e73332e61702d736f757468656173742d312e616d617a6f6e6177732e636f6d2f7374617469632f73746f726566726f6e742d6c6f676f2e737667" width="380" height="100" />
    </p>
    <p align="center">
        Open-source Headless Commerce & Marketplace Extension for Fleetbase
    </p>
</p>

---

## Overview

This monorepo contains both the frontend and backend components of the Storefront extension for Fleetbase. The frontend is built using Ember.js and the backend is implemented in PHP.

### Requirements

- PHP 7.3.0 or above
- Ember.js v5.4 or above
- Ember CLI v5.4 or above
- Node.js v18 or above

## Structure

```
├── addon
├── app
├── assets
├── translations
├── config
├── node_modules
├── server
│ ├── config
│ ├── data
│ ├── migrations
│ ├── resources
│ ├── src
│ ├── tests
│ └── vendor
├── tests
├── testem.js
├── index.js
├── package.json
├── phpstan.neon.dist
├── phpunit.xml.dist
├── pnpm-lock.yaml
├── ember-cli-build.js
├── composer.json
├── CONTRIBUTING.md
├── LICENSE.md
├── README.md
```

## Installation

### Backend

Install the PHP packages using Composer:

```bash
composer require fleetbase/core-api
composer require fleetbase/fleetops-api
composer require fleetbase/storefront-api
```
### Frontend

Install the Ember.js Engine/Addon:

```bash
pnpm install @fleetbase/storefront-engine
```

## Usage

### Backend

🧹 Keep a modern codebase with **PHP CS Fixer**:
```bash
composer lint
```

⚗️ Run static analysis using **PHPStan**:
```bash
composer test:types
```

✅ Run unit tests using **PEST**
```bash
composer test:unit
```

🚀 Run the entire test suite:
```bash
composer test
```

### Frontend

🧹 Keep a modern codebase with **ESLint**:
```bash
pnpm lint
```

✅ Run unit tests using **Ember/QUnit**
```bash
pnpm test
pnpm test:ember
pnpm test:ember-compatibility
```

🚀 Start the Ember Addon/Engine
```bash
pnpm start
```

🔨 Build the Ember Addon/Engine
```bash
pnpm build
```

## Contributing
See the Contributing Guide for details on how to contribute to this project.

## License
This project is licensed under the MIT License.
