<?php

use Fleetbase\Models\User;
use Fleetbase\Storefront\Http\Controllers\NetworkController;
use Fleetbase\Storefront\Http\Requests\AddStoreToNetworkCategory;
use Fleetbase\Storefront\Http\Requests\NetworkActionRequest;
use Fleetbase\Storefront\Models\Network;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

function createNetworkScopingSchema(): void
{
    $schema = Capsule::schema('mysql');

    foreach (['networks', 'stores', 'network_stores', 'invites', 'categories'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('currency')->nullable();
        $table->text('options')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->string('status')->default('active');
        $table->string('category_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('invites', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('protocol')->nullable();
        $table->text('recipients')->nullable();
        $table->string('reason')->nullable();
        $table->string('uri')->nullable();
        $table->string('code')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('owner_uuid');
        $table->timestamps();
        $table->softDeletes();
    });

    $connection = Capsule::connection('mysql');
    $options    = json_encode(['shareable_link_enabled' => true, 'shareable_link_token' => 'other-link-token']);
    $connection->table('networks')->insert([
        ['uuid' => 'network_uuid', 'public_id' => 'network_public', 'company_uuid' => 'company_uuid', 'name' => 'Night Market', 'currency' => 'MNT', 'options' => null],
        ['uuid' => 'other_network_uuid', 'public_id' => 'network_other', 'company_uuid' => 'other_company_uuid', 'name' => 'Other Market', 'currency' => 'MNT', 'options' => $options],
    ]);
    $connection->table('stores')->insert([
        ['uuid' => 'store_uuid', 'public_id' => 'store_public', 'company_uuid' => 'company_uuid', 'name' => 'Corner Store'],
        ['uuid' => 'second_store_uuid', 'public_id' => 'store_second', 'company_uuid' => 'company_uuid', 'name' => 'Second Store'],
        ['uuid' => 'other_store_uuid', 'public_id' => 'store_other', 'company_uuid' => 'other_company_uuid', 'name' => 'Other Store'],
    ]);
    // The other company's network has the current company's store and its own store as members.
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_uuid', 'status' => 'active', 'category_uuid' => 'category_uuid'],
        ['network_uuid' => 'other_network_uuid', 'store_uuid' => 'store_uuid', 'status' => 'pending', 'category_uuid' => 'other_category_uuid'],
        ['network_uuid' => 'other_network_uuid', 'store_uuid' => 'other_store_uuid', 'status' => 'active', 'category_uuid' => 'other_category_uuid'],
    ]);
    $connection->table('invites')->insert([
        ['uuid' => 'other_invite_uuid', 'public_id' => 'invite_other', 'company_uuid' => 'other_company_uuid', 'subject_uuid' => 'other_network_uuid', 'reason' => 'join_storefront_network', 'protocol' => 'link', 'uri' => 'other-invite', 'meta' => json_encode(['status' => 'awaiting_approval', 'accepted_store' => 'store_uuid'])],
    ]);
    $connection->table('categories')->insert([
        ['uuid' => 'category_uuid', 'owner_uuid' => 'network_uuid'],
        ['uuid' => 'other_category_uuid', 'owner_uuid' => 'other_network_uuid'],
    ]);
}

/**
 * Everything a network action could change, to show a refused action changed nothing.
 */
function networkScopingSnapshot(): array
{
    $connection = Capsule::connection('mysql');

    return [
        'networks'       => $connection->table('networks')->orderBy('id')->get(['uuid', 'options'])->toArray(),
        'network_stores' => $connection->table('network_stores')->orderBy('id')->get(['network_uuid', 'store_uuid', 'status', 'category_uuid', 'deleted_at'])->toArray(),
        'invites'        => $connection->table('invites')->orderBy('id')->get(['uuid', 'meta', 'expires_at', 'deleted_at'])->toArray(),
        'categories'     => $connection->table('categories')->orderBy('id')->get(['uuid', 'deleted_at'])->toArray(),
    ];
}

function fakeNetworkScopingMail(): object
{
    $mail = new class {
        public array $sent = [];

        public function send($mailable): void
        {
            $this->sent[] = $mailable;
        }
    };
    Mail::swap($mail);

    return $mail;
}

function networkActionRequest(array $input = []): NetworkActionRequest
{
    $request = NetworkActionRequest::create('/', 'POST', $input);
    $request->setUserResolver(fn () => (new User())->forceFill(['uuid' => 'user_uuid', 'name' => 'Network owner']));

    return $request;
}

/**
 * Every internal network action, called on the given network id.
 */
function networkScopedActions(): array
{
    return [
        'sendInvites'         => fn (NetworkController $controller, string $id) => $controller->sendInvites($id, networkActionRequest(['recipients' => ['store@example.test']])),
        'invitations'         => fn (NetworkController $controller, string $id) => $controller->invitations($id),
        'resendInvitation'    => fn (NetworkController $controller, string $id) => $controller->resendInvitation($id, 'other_invite_uuid', Request::create('/', 'POST', ['expires_in_days' => 30])),
        'revokeInvitation'    => fn (NetworkController $controller, string $id) => $controller->revokeInvitation($id, 'other_invite_uuid'),
        'overview'            => fn (NetworkController $controller, string $id) => $controller->overview($id),
        'addStores'           => fn (NetworkController $controller, string $id) => $controller->addStores($id, networkActionRequest(['stores' => ['second_store_uuid'], 'remove' => ['store_uuid', 'other_store_uuid']])),
        'removeStores'        => fn (NetworkController $controller, string $id) => $controller->removeStores($id, networkActionRequest(['stores' => ['store_uuid', 'other_store_uuid']])),
        'suspendStores'       => fn (NetworkController $controller, string $id) => $controller->suspendStores($id, networkActionRequest(['stores' => ['store_uuid', 'other_store_uuid']])),
        'reinstateStores'     => fn (NetworkController $controller, string $id) => $controller->reinstateStores($id, networkActionRequest(['stores' => ['store_uuid', 'other_store_uuid']])),
        'approveStores'       => fn (NetworkController $controller, string $id) => $controller->approveStores($id, networkActionRequest(['stores' => ['store_uuid']])),
        'rejectStores'        => fn (NetworkController $controller, string $id) => $controller->rejectStores($id, networkActionRequest(['stores' => ['store_uuid']])),
        'updateShareableLink' => fn (NetworkController $controller, string $id) => $controller->updateShareableLink($id, networkActionRequest(['enabled' => false, 'regenerate' => true])),
        'addStoreToCategory'  => fn (NetworkController $controller, string $id) => $controller->addStoreToCategory($id, AddStoreToNetworkCategory::create('/', 'POST', ['store' => 'store_uuid', 'category' => 'category_uuid'])),
        'removeStoreCategory' => fn (NetworkController $controller, string $id) => $controller->removeStoreCategory($id, networkActionRequest(['store' => 'store_uuid'])),
        'deleteCategory'      => fn (NetworkController $controller, string $id) => $controller->deleteCategory($id, networkActionRequest(['category' => 'other_category_uuid'])),
    ];
}

dataset('network scoped actions', fn () => collect(networkScopedActions())->map(fn ($action) => [$action])->all());

test('network actions return 404 and change nothing for another company\'s network', function (Closure $action) {
    createNetworkScopingSchema();
    session(['company' => 'company_uuid', 'user' => 'user_uuid']);
    $mail   = fakeNetworkScopingMail();
    $before = networkScopingSnapshot();

    foreach (['other_network_uuid', 'network_other'] as $id) {
        $response = $action(new NetworkController(), $id);

        expect($response->getStatusCode())->toBe(404);
    }

    expect(networkScopingSnapshot())->toEqual($before)
        ->and($mail->sent)->toBe([]);
})->with('network scoped actions');

test('network actions return 404 without a session company', function (Closure $action) {
    createNetworkScopingSchema();
    Capsule::connection('mysql')->table('networks')->where('uuid', 'network_uuid')->update(['company_uuid' => null]);
    session(['company' => null, 'user' => 'user_uuid']);
    $mail   = fakeNetworkScopingMail();
    $before = networkScopingSnapshot();

    expect($action(new NetworkController(), 'network_uuid')->getStatusCode())->toBe(404)
        ->and(networkScopingSnapshot())->toEqual($before)
        ->and($mail->sent)->toBe([]);
})->with('network scoped actions');

test('network actions still work on the current company\'s network by uuid or public id', function () {
    createNetworkScopingSchema();
    session(['company' => 'company_uuid', 'user' => 'user_uuid']);
    $mail       = fakeNetworkScopingMail();
    $controller = new NetworkController();

    expect($controller->invitations('network_public')->getStatusCode())->toBe(200)
        ->and($controller->sendInvites('network_uuid', networkActionRequest(['recipients' => ['store@example.test']]))->getStatusCode())->toBe(200)
        ->and($controller->suspendStores('network_public', networkActionRequest(['stores' => ['store_uuid']]))->getData(true))->toBe(['status' => 'ok', 'updated' => 1])
        ->and($controller->updateShareableLink('network_uuid', networkActionRequest(['enabled' => true]))->getStatusCode())->toBe(200)
        ->and($mail->sent)->toHaveCount(1);

    $connection = Capsule::connection('mysql');
    expect($connection->table('invites')->where('subject_uuid', 'network_uuid')->count())->toBe(1)
        ->and($connection->table('network_stores')->where(['network_uuid' => 'network_uuid', 'store_uuid' => 'store_uuid'])->value('status'))->toBe('suspended')
        ->and($connection->table('network_stores')->where(['network_uuid' => 'other_network_uuid', 'store_uuid' => 'store_uuid'])->value('status'))->toBe('pending')
        ->and(Network::where('uuid', 'network_uuid')->first()->options['shareable_link_enabled'])->toBeTrue();
});

test('add stores only removes memberships of this network', function () {
    createNetworkScopingSchema();
    session(['company' => 'company_uuid']);

    $response = (new NetworkController())->addStores('network_uuid', networkActionRequest(['stores' => [], 'remove' => ['store_uuid']]));

    $connection = Capsule::connection('mysql');
    expect($response->getData(true))->toBe(['status' => 'ok'])
        ->and($connection->table('network_stores')->where(['network_uuid' => 'network_uuid', 'store_uuid' => 'store_uuid'])->value('deleted_at'))->not->toBeNull()
        ->and($connection->table('network_stores')->where(['network_uuid' => 'other_network_uuid', 'store_uuid' => 'store_uuid'])->value('deleted_at'))->toBeNull();
});

test('add stores adds the company\'s own stores, keeps existing members and never adds another company\'s store', function () {
    createNetworkScopingSchema();
    session(['company' => 'company_uuid']);
    $connection = Capsule::connection('mysql');
    $connection->table('network_stores')->insert(['network_uuid' => 'network_uuid', 'store_uuid' => 'member_store_uuid', 'status' => 'active']);

    $response = (new NetworkController())->addStores('network_uuid', networkActionRequest([
        'stores' => ['store_uuid', 'second_store_uuid', 'member_store_uuid', 'other_store_uuid'],
    ]));

    $members = $connection->table('network_stores')->where('network_uuid', 'network_uuid')->whereNull('deleted_at')->pluck('store_uuid')->sort()->values()->all();
    expect($response->getData(true))->toBe(['status' => 'ok'])
        ->and($members)->toBe(['member_store_uuid', 'second_store_uuid', 'store_uuid']);
});
