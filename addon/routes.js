import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    this.route('home', { path: '/' });
    this.route('products', function () {
        this.route('index', { path: '/' }, function () {
            this.route('index', { path: '/' }, function () {
                this.route('edit', { path: '/:public_id' });
            });
            this.route('category', { path: '/:slug' }, function () {
                this.route('new');
                this.route('edit', { path: '/:public_id' });
            });
        });
    });
    this.route('catalogs', function () {
        this.route('index', { path: '/' }, function () {});
    });
    this.route('customers', function () {
        this.route('index', { path: '/' }, function () {
            this.route('edit', { path: '/:public_id' });
            this.route('view', { path: '/:public_id' });
        });
    });
    this.route('orders', function () {
        this.route('index', { path: '/' }, function () {
            this.route('new');
            this.route('edit', { path: '/:public_id' });
            this.route('view', { path: '/:public_id' });
        });
    });
    this.route('networks', function () {
        this.route('index', { path: '/' }, function () {
            this.route('network', { path: '/:public_id' }, function () {
                this.route('stores');
                this.route('customers');
                this.route('orders');
            });
        });
    });
    this.route('food-trucks', function () {
        this.route('index', { path: '/' }, function () {});
    });
    this.route('promotions', function () {
        this.route('push-notifications', { path: '/' });
    });
    this.route('coupons');
    this.route('broadcast');
    this.route('pages');
    this.route('settings', function () {
        this.route('index', { path: '/' });
        this.route('api');
        this.route('locations');
        this.route('gateways');
        this.route('notifications');
    });
});
