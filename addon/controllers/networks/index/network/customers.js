import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { isBlank } from '@ember/utils';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';
import CustomersIndexController from '../../../customers';
import { action } from '@ember/object';
export default class NetworksIndexNetworkCustomersController extends CustomersIndexController {
    @service contextPanel;

    @tracked columns = [
        {
            label: this.intl.t('storefront.common.name'),
            valuePath: 'name',
            width: '25%',
            cellComponent: 'table/cell/media-name',
            action: this.viewCustomer,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('storefront.common.id'),
            valuePath: 'public_id',
            cellComponent: 'click-to-copy',
            width: '15%',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('storefront.common.email'),
            valuePath: 'email',
            cellComponent: 'table/cell/base',
            width: '20%',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('storefront.common.phone'),
            valuePath: 'phone',
            cellComponent: 'table/cell/base',
            width: '16%',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('storefront.customers.index.create-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '20%',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
    ];

    @action viewCustomer(customer) {
        this.contextPanel.focus(customer, 'viewing');
    }
}
