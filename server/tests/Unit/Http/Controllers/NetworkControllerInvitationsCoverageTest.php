<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Storefront\Http\Controllers\NetworkController;
use Fleetbase\Storefront\Http\Requests\NetworkActionRequest;
use Fleetbase\Storefront\Mail\StorefrontNetworkInvite;
use Fleetbase\Storefront\Models\Network;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

const NETWORK_COVERAGE_NOW = '2026-10-14 12:00:00';

function networkCoverageSchema(): void
{
    $schema = Capsule::schema('mysql');

    foreach (['networks', 'stores', 'network_stores', 'invites', 'categories', 'orders', 'contacts', 'tracking_numbers'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('currency')->nullable();
        $table->string('timezone')->nullable();
        $table->string('website')->nullable();
        $table->boolean('online')->default(false);
        $table->string('logo_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
        $table->string('slug')->nullable();
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
        $table->string('email')->nullable();
        $table->string('currency')->nullable();
        $table->boolean('online')->default(false);
        $table->string('logo_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
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
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('type')->nullable();
        $table->string('status')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('tracking_number_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('type')->nullable();
        $table->string('photo_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('tracking_numbers', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('tracking_number')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $connection = Capsule::connection('mysql');
    $connection->table('networks')->insert([
        'uuid'        => 'network_uuid',
        'public_id'   => 'network_public',
        'company_uuid'=> 'company_uuid',
        'name'        => 'Night Market',
        'description' => 'Late night food',
        'currency'    => 'MNT',
        'timezone'    => 'Asia/Ulaanbaatar',
        'website'     => 'https://night.example.test',
        'online'      => true,
    ]);
    $connection->table('stores')->insert([
        ['uuid' => 'store_uuid', 'public_id' => 'store_public', 'company_uuid' => 'company_uuid', 'name' => 'Corner Store', 'email' => 'Corner@Example.test', 'currency' => 'MNT', 'online' => true],
        ['uuid' => 'second_store_uuid', 'public_id' => 'store_second', 'company_uuid' => 'company_uuid', 'name' => 'Second Store', 'email' => null, 'currency' => 'MNT', 'online' => false],
        ['uuid' => 'third_store_uuid', 'public_id' => 'store_third', 'company_uuid' => 'company_uuid', 'name' => 'Third Store', 'email' => 'third@example.test', 'currency' => 'MNT', 'online' => true],
        ['uuid' => 'other_store_uuid', 'public_id' => 'store_other', 'company_uuid' => 'other_company_uuid', 'name' => 'Other Store', 'email' => 'other@example.test', 'currency' => 'MNT', 'online' => true],
    ]);
    $connection->table('categories')->insert([
        ['uuid' => 'category_uuid', 'owner_uuid' => 'network_uuid', 'name' => 'Food'],
    ]);
}

function networkCoverageMail(): object
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

function networkCoverageRequest(array $input = []): NetworkActionRequest
{
    $request = NetworkActionRequest::create('/', 'POST', $input);
    $request->setUserResolver(fn () => (new User())->forceFill(['uuid' => 'user_uuid', 'name' => 'Network owner']));

    return $request;
}

/**
 * An invitation row for the seeded network, written straight to the table.
 */
function networkCoverageInvite(array $attributes): void
{
    $attributes = array_merge([
        'company_uuid' => 'company_uuid',
        'subject_uuid' => 'network_uuid',
        'subject_type' => Network::class,
        'reason'       => 'join_storefront_network',
        'protocol'     => 'email',
        'recipients'   => json_encode([]),
        'meta'         => json_encode(['status' => 'pending']),
        'created_at'   => NETWORK_COVERAGE_NOW,
        'updated_at'   => NETWORK_COVERAGE_NOW,
    ], $attributes);

    Capsule::connection('mysql')->table('invites')->insert($attributes);
}

function networkCoverageInviteMeta(string $uuid): array
{
    return json_decode(Capsule::connection('mysql')->table('invites')->where('uuid', $uuid)->value('meta'), true);
}

function networkCoverageSetLink(array $options): void
{
    Capsule::connection('mysql')->table('networks')->where('uuid', 'network_uuid')->update(['options' => json_encode($options)]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(NETWORK_COVERAGE_NOW));
    networkCoverageSchema();
    session(['company' => 'company_uuid', 'user' => 'user_uuid']);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('sending invites mails each valid address once and sets the requested expiry', function () {
    $mail = networkCoverageMail();

    $response = (new NetworkController())->sendInvites('network_uuid', networkCoverageRequest([
        'recipients'       => ['Store@Example.test', 'store@example.test', 'not-an-email', 42, 'second@example.test'],
        'expires_in_days'  => 7,
        'message'          => 'Join us',
        'require_approval' => true,
    ]));

    $rows = Capsule::connection('mysql')->table('invites')->orderBy('id')->get();
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($rows)->toHaveCount(2)
        ->and(json_decode($rows[0]->recipients, true))->toBe(['store@example.test'])
        ->and(json_decode($rows[1]->recipients, true))->toBe(['second@example.test'])
        ->and($rows[0]->expires_at)->toBe('2026-10-21 12:00:00')
        ->and($rows[1]->expires_at)->toBe('2026-10-21 12:00:00')
        ->and(json_decode($rows[0]->meta, true))->toBe(['status' => 'pending', 'message' => 'Join us', 'require_approval' => true])
        ->and($data['invitations'])->toHaveCount(2)
        ->and($data['invitations'][0]['email'])->toBe('store@example.test')
        ->and($data['invitations'][0]['status'])->toBe('pending')
        ->and($data['invitations'][0]['require_approval'])->toBeTrue()
        ->and($mail->sent)->toHaveCount(2)
        ->and($mail->sent[0])->toBeInstanceOf(StorefrontNetworkInvite::class);
});

test('resending an unknown invitation is refused without mailing', function () {
    $mail = networkCoverageMail();

    $response = (new NetworkController())->resendInvitation('network_uuid', 'missing_invite', Request::create('/', 'POST'));

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe(['error' => 'Invitation not found.'])
        ->and($mail->sent)->toBe([]);
});

test('resending an invitation reopens it, stamps the resend and applies a new expiry', function () {
    $mail = networkCoverageMail();
    networkCoverageInvite(['uuid' => 'invite_uuid', 'public_id' => 'invite_public', 'recipients' => json_encode(['store@example.test']), 'meta' => json_encode(['status' => 'declined', 'message' => 'Hi'])]);

    $response = (new NetworkController())->resendInvitation('network_public', 'invite_public', networkCoverageRequest(['expires_in_days' => 30]));

    $meta = networkCoverageInviteMeta('invite_uuid');
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['invitation']['status'])->toBe('pending')
        ->and($response->getData(true)['invitation']['resent_at'])->toBe(Carbon::now()->toIso8601String())
        ->and($meta)->toBe(['status' => 'pending', 'message' => 'Hi', 'resent_at' => Carbon::now()->toIso8601String()])
        ->and(Capsule::connection('mysql')->table('invites')->where('uuid', 'invite_uuid')->value('expires_at'))->toBe('2026-11-13 12:00:00')
        ->and($mail->sent)->toHaveCount(1)
        ->and($mail->sent[0]->invite->uuid)->toBe('invite_uuid');
});

test('resending an invitation that is still open keeps its expiry', function () {
    networkCoverageMail();
    networkCoverageInvite(['uuid' => 'invite_uuid', 'public_id' => 'invite_public', 'expires_at' => '2026-10-20 12:00:00']);

    $response = (new NetworkController())->resendInvitation('network_uuid', 'invite_uuid', networkCoverageRequest(['expires_in_days' => 'soon']));

    expect($response->getStatusCode())->toBe(200)
        ->and(Capsule::connection('mysql')->table('invites')->where('uuid', 'invite_uuid')->value('expires_at'))->toBe('2026-10-20 12:00:00');
});

test('an invitation that expires while it is being resent gets a fresh fourteen day expiry', function () {
    networkCoverageMail();
    networkCoverageInvite(['uuid' => 'invite_uuid', 'public_id' => 'invite_public', 'expires_at' => '2026-10-15 12:00:00']);
    new Invite();

    // The invitation is still open when it is looked up, and expires right after.
    Invite::setEventDispatcher(new Dispatcher(app()));
    Invite::retrieved(fn () => Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00')));

    try {
        $response = (new NetworkController())->resendInvitation('network_uuid', 'invite_uuid', networkCoverageRequest());
    } finally {
        Invite::unsetEventDispatcher();
    }

    expect($response->getStatusCode())->toBe(200)
        ->and(Capsule::connection('mysql')->table('invites')->where('uuid', 'invite_uuid')->value('expires_at'))->toBe('2026-10-30 12:00:00');
});

test('revoking an invitation removes it from the network\'s list and refuses an unknown one', function () {
    networkCoverageInvite(['uuid' => 'invite_uuid', 'public_id' => 'invite_public', 'recipients' => json_encode(['store@example.test'])]);
    networkCoverageInvite(['uuid' => 'kept_uuid', 'public_id' => 'invite_kept', 'recipients' => json_encode(['kept@example.test'])]);
    $controller = new NetworkController();

    $missing = $controller->revokeInvitation('network_uuid', 'missing_invite');
    $revoked = $controller->revokeInvitation('network_uuid', 'invite_public');

    expect($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Invitation not found.'])
        ->and($revoked->getData(true))->toBe(['status' => 'ok'])
        ->and(Capsule::connection('mysql')->table('invites')->where('uuid', 'invite_uuid')->value('deleted_at'))->not->toBeNull()
        ->and(collect($controller->invitations('network_uuid')->getData(true))->pluck('uuid')->all())->toBe(['kept_uuid']);
});

test('an expired invitation is serialized as expired, an open one keeps its status', function () {
    $expired  = (new Invite())->forceFill(['uuid' => 'invite_uuid', 'recipients' => ['store@example.test'], 'meta' => ['status' => 'pending'], 'expires_at' => '2026-10-13 12:00:00', 'protocol' => 'link']);
    $declined = (new Invite())->forceFill(['uuid' => 'declined_uuid', 'meta' => ['status' => 'declined', 'declined_at' => 'yesterday'], 'expires_at' => '2026-10-13 12:00:00']);

    $serialized = NetworkController::serializeInvitation($expired);

    expect($serialized['status'])->toBe('expired')
        ->and($serialized['email'])->toBe('store@example.test')
        ->and($serialized['via'])->toBe('link')
        ->and($serialized['expires_at']->toDateTimeString())->toBe('2026-10-13 12:00:00')
        ->and(NetworkController::serializeInvitation($declined)['status'])->toBe('declined')
        ->and(NetworkController::serializeInvitation($declined)['declined_at'])->toBe('yesterday')
        ->and(NetworkController::serializeInvitation($declined)['email'])->toBeNull();
});

test('looking up an unknown join code with no shareable link reports it unavailable', function () {
    $response = (new NetworkController())->lookupInvitation('nothing-here');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe(['error' => 'This invitation is no longer available.']);
});

test('looking up the shareable join link answers with the link settings, its category and the network', function () {
    networkCoverageSetLink([
        'shareable_link_enabled'           => true,
        'shareable_link_token'             => 'join-token',
        'shareable_link_expires_at'        => '2026-10-30T12:00:00+00:00',
        'shareable_link_category_uuid'     => 'category_uuid',
        'shareable_link_requires_approval' => false,
    ]);
    Capsule::connection('mysql')->table('network_stores')->insert(['network_uuid' => 'network_uuid', 'store_uuid' => 'store_uuid', 'status' => 'active']);
    networkCoverageInvite(['uuid' => 'link_join_uuid', 'protocol' => 'link']);

    $data = (new NetworkController())->lookupInvitation('join-token')->getData(true);

    expect($data['sender'])->toBeNull()
        ->and($data['category'])->toBe(['id' => 'category_uuid', 'name' => 'Food'])
        ->and($data['invitation'])->toMatchArray([
            'id'               => null,
            'uri'              => 'join-token',
            'email'            => null,
            'recipients'       => [],
            'status'           => 'pending',
            'via'              => 'link',
            'category_uuid'    => 'category_uuid',
            'require_approval' => false,
        ])
        ->and(Carbon::parse($data['invitation']['expires_at'])->toDateTimeString())->toBe('2026-10-30 12:00:00')
        ->and($data['network'])->toMatchArray([
            'id'           => 'network_uuid',
            'public_id'    => 'network_public',
            'name'         => 'Night Market',
            'description'  => 'Late night food',
            'currency'     => 'MNT',
            'timezone'     => 'Asia/Ulaanbaatar',
            'stores_count' => 1,
            'online'       => true,
            'website'      => 'https://night.example.test',
        ]);
});

test('a shareable link without a category or expiry looks up with neither', function () {
    networkCoverageSetLink(['shareable_link_enabled' => true, 'shareable_link_token' => 'join-token']);

    $data = (new NetworkController())->lookupInvitation('join-token')->getData(true);

    expect($data['category'])->toBeNull()
        ->and($data['invitation']['expires_at'])->toBeNull()
        ->and($data['invitation']['require_approval'])->toBeTrue()
        ->and($data['network']['stores_count'])->toBe(0);
});

test('an invitation whose network is gone is no longer available to look up or accept', function () {
    networkCoverageInvite(['uuid' => 'orphan_uuid', 'uri' => 'orphan-invite', 'subject_uuid' => 'deleted_network_uuid']);
    $controller = new NetworkController();

    $lookup = $controller->lookupInvitation('orphan-invite');
    $accept = $controller->acceptInvitation('orphan-invite', Request::create('/', 'POST', ['store' => 'store_uuid']));

    expect($lookup->getStatusCode())->toBe(404)
        ->and($lookup->getData(true))->toBe(['error' => 'This invitation is no longer available.'])
        ->and($accept->getStatusCode())->toBe(404)
        ->and(Capsule::connection('mysql')->table('network_stores')->count())->toBe(0);
});

test('the shareable link is closed when it is off, regenerated or expired, and older public id links still open', function () {
    $controller = new NetworkController();

    // Off
    networkCoverageSetLink(['shareable_link_enabled' => false, 'shareable_link_token' => 'join-token']);
    expect($controller->declineInvitation('join-token')->getStatusCode())->toBe(404);

    // Regenerated: the network's public id no longer opens it
    networkCoverageSetLink(['shareable_link_enabled' => true, 'shareable_link_token' => 'join-token']);
    expect($controller->declineInvitation('network_public')->getStatusCode())->toBe(404)
        ->and($controller->declineInvitation('join-token')->getData(true))->toBe(['status' => 'ok']);

    // Expired
    networkCoverageSetLink(['shareable_link_enabled' => true, 'shareable_link_token' => 'join-token', 'shareable_link_expires_at' => '2026-10-13T12:00:00+00:00']);
    expect($controller->declineInvitation('join-token')->getStatusCode())->toBe(404);

    // An older link made before tokens: the public id
    networkCoverageSetLink(['shareable_link_enabled' => true]);
    expect($controller->declineInvitation('network_public')->getData(true))->toBe(['status' => 'ok'])
        ->and($controller->declineInvitation('unknown-link')->getData(true))->toBe(['error' => 'This invitation is no longer available.']);
});

test('declining an invitation closes it as declined', function () {
    networkCoverageInvite(['uuid' => 'invite_uuid', 'uri' => 'invite-code', 'meta' => json_encode(['status' => 'pending', 'message' => 'Hi'])]);

    $response = (new NetworkController())->declineInvitation('invite-code');

    expect($response->getData(true))->toBe(['status' => 'ok'])
        ->and(networkCoverageInviteMeta('invite_uuid'))->toBe(['status' => 'declined', 'message' => 'Hi', 'declined_at' => Carbon::now()->toIso8601String()]);
});

test('joining through the shareable link refuses an unknown link, another company\'s store and an existing member', function () {
    $controller = new NetworkController();

    $unknown = $controller->acceptInvitation('join-token', Request::create('/', 'POST', ['store' => 'store_uuid']));

    networkCoverageSetLink(['shareable_link_enabled' => true, 'shareable_link_token' => 'join-token']);
    Capsule::connection('mysql')->table('network_stores')->insert(['network_uuid' => 'network_uuid', 'store_uuid' => 'third_store_uuid', 'status' => 'active']);

    $otherStore = $controller->acceptInvitation('join-token', Request::create('/', 'POST', ['store' => 'store_other']));
    $member     = $controller->acceptInvitation('join-token', Request::create('/', 'POST', ['store' => 'store_third']));

    expect($unknown->getStatusCode())->toBe(404)
        ->and($otherStore->getStatusCode())->toBe(422)
        ->and($otherStore->getData(true))->toBe(['error' => 'Pick one of your stores to join with.'])
        ->and($member->getStatusCode())->toBe(422)
        ->and($member->getData(true))->toBe(['error' => 'This store is already a member of the network.'])
        ->and(Capsule::connection('mysql')->table('invites')->count())->toBe(0)
        ->and(Capsule::connection('mysql')->table('network_stores')->count())->toBe(1);
});

test('joining through the shareable link records the join and leaves the store awaiting approval by default', function () {
    networkCoverageSetLink(['shareable_link_enabled' => true, 'shareable_link_token' => 'join-token', 'shareable_link_category_uuid' => 'category_uuid']);

    $response = (new NetworkController())->acceptInvitation('join-token', Request::create('/', 'POST', ['store' => 'store_public']));

    $connection = Capsule::connection('mysql');
    $invite     = $connection->table('invites')->first();
    $membership = $connection->table('network_stores')->where(['network_uuid' => 'network_uuid', 'store_uuid' => 'store_uuid'])->first();

    expect($response->getData(true))->toBe([
        'status'            => 'ok',
        'network'           => ['id' => 'network_uuid', 'public_id' => 'network_public', 'name' => 'Night Market'],
        'store'             => ['id' => 'store_uuid', 'public_id' => 'store_public', 'name' => 'Corner Store'],
        'awaiting_approval' => true,
    ])
        ->and($invite->protocol)->toBe('link')
        ->and($invite->company_uuid)->toBe('company_uuid')
        ->and($invite->created_by_uuid)->toBe('user_uuid')
        ->and(json_decode($invite->recipients, true))->toBe(['Corner@Example.test'])
        ->and(json_decode($invite->meta, true))->toBe([
            'status'           => 'awaiting_approval',
            'category_uuid'    => 'category_uuid',
            'require_approval' => true,
            'via'              => 'link',
            'store_name'       => 'Corner Store',
            'accepted_at'      => Carbon::now()->toIso8601String(),
            'accepted_store'   => 'store_uuid',
        ])
        ->and($membership->status)->toBe('pending')
        ->and($membership->category_uuid)->toBe('category_uuid');
});

test('joining through a link that needs no review makes the store an active member', function () {
    networkCoverageSetLink(['shareable_link_enabled' => true, 'shareable_link_token' => 'join-token', 'shareable_link_requires_approval' => false]);

    $response = (new NetworkController())->acceptInvitation('join-token', Request::create('/', 'POST', ['store' => 'second_store_uuid']));

    $invite = Capsule::connection('mysql')->table('invites')->first();
    expect($response->getData(true)['awaiting_approval'])->toBeFalse()
        ->and(json_decode($invite->recipients, true))->toBe([])
        ->and(json_decode($invite->meta, true)['status'])->toBe('accepted')
        ->and(Capsule::connection('mysql')->table('network_stores')->where('store_uuid', 'second_store_uuid')->value('status'))->toBe('active');
});

test('an invitation that is no longer pending, or a store that is not the company\'s, cannot be accepted', function () {
    networkCoverageInvite(['uuid' => 'declined_uuid', 'uri' => 'declined-invite', 'meta' => json_encode(['status' => 'declined'])]);
    networkCoverageInvite(['uuid' => 'pending_uuid', 'uri' => 'pending-invite']);
    $controller = new NetworkController();

    $declined = $controller->acceptInvitation('declined-invite', Request::create('/', 'POST', ['store' => 'store_uuid']));
    $noStore  = $controller->acceptInvitation('pending-invite', Request::create('/', 'POST', ['store' => 'other_store_uuid']));

    expect($declined->getStatusCode())->toBe(422)
        ->and($declined->getData(true))->toBe(['error' => 'This invitation can no longer be accepted.'])
        ->and($noStore->getStatusCode())->toBe(422)
        ->and($noStore->getData(true))->toBe(['error' => 'Pick one of your stores to join with.'])
        ->and(networkCoverageInviteMeta('pending_uuid'))->toBe(['status' => 'pending'])
        ->and(Capsule::connection('mysql')->table('network_stores')->count())->toBe(0);
});

test('a store\'s dashboard lists the open invitations addressed to its email with their network', function () {
    networkCoverageInvite(['uuid' => 'open_uuid', 'public_id' => 'invite_open', 'recipients' => json_encode(['corner@example.test']), 'created_at' => '2026-10-13 12:00:00']);
    networkCoverageInvite(['uuid' => 'newer_uuid', 'public_id' => 'invite_newer', 'recipients' => json_encode(['corner@example.test']), 'expires_at' => '2026-10-20 12:00:00']);
    networkCoverageInvite(['uuid' => 'declined_uuid', 'recipients' => json_encode(['corner@example.test']), 'meta' => json_encode(['status' => 'declined'])]);
    networkCoverageInvite(['uuid' => 'orphan_uuid', 'recipients' => json_encode(['corner@example.test']), 'subject_uuid' => 'deleted_network_uuid']);
    networkCoverageInvite(['uuid' => 'other_uuid', 'recipients' => json_encode(['third@example.test'])]);
    networkCoverageInvite(['uuid' => 'company_uuid_invite', 'recipients' => json_encode(['corner@example.test']), 'reason' => 'join_company']);
    $controller = new NetworkController();

    $byPublicId = $controller->pendingInvitationsForStore(Request::create('/', 'GET', ['storefront' => 'store_public']))->getData(true);
    $byUuid     = $controller->pendingInvitationsForStore(Request::create('/', 'GET', ['store' => 'store_uuid']))->getData(true);

    expect(collect($byPublicId)->pluck('uuid')->all())->toBe(['newer_uuid', 'open_uuid'])
        ->and($byPublicId[0]['network'])->toBe([
            'id'        => 'network_uuid',
            'public_id' => 'network_public',
            'name'      => 'Night Market',
            'logo_url'  => 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png',
            'currency'  => 'MNT',
        ])
        ->and($byPublicId[0]['email'])->toBe('corner@example.test')
        ->and($byUuid)->toBe($byPublicId);
});

test('a store without an email, another company\'s store or no store has no pending invitations', function () {
    networkCoverageInvite(['uuid' => 'open_uuid', 'recipients' => json_encode(['other@example.test'])]);
    $controller = new NetworkController();

    expect($controller->pendingInvitationsForStore(Request::create('/', 'GET', ['store' => 'second_store_uuid']))->getData(true))->toBe([])
        ->and($controller->pendingInvitationsForStore(Request::create('/', 'GET', ['store' => 'other_store_uuid']))->getData(true))->toBe([])
        ->and($controller->pendingInvitationsForStore(Request::create('/', 'GET'))->getData(true))->toBe([]);
});

test('the network overview reports members, invitations, the last seven days of orders and customers', function () {
    $connection = Capsule::connection('mysql');
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_uuid', 'status' => 'active', 'category_uuid' => 'category_uuid'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'second_store_uuid', 'status' => 'pending', 'category_uuid' => null],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'third_store_uuid', 'status' => 'active', 'category_uuid' => null],
    ]);
    networkCoverageInvite(['uuid' => 'pending_invite', 'created_at' => '2026-10-10 12:00:00']);
    networkCoverageInvite(['uuid' => 'declined_invite', 'meta' => json_encode(['status' => 'declined'])]);
    $connection->table('contacts')->insert([
        ['uuid' => 'customer_one', 'public_id' => 'contact_one', 'name' => 'Bat', 'type' => 'customer'],
        ['uuid' => 'customer_two', 'public_id' => 'contact_two', 'name' => 'Saraa', 'type' => 'customer'],
    ]);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tracking_uuid', 'tracking_number' => 'TRK-1']);
    $order = fn (array $attributes, array $meta) => array_merge([
        'company_uuid'         => 'company_uuid',
        'type'                 => 'storefront',
        'status'               => 'created',
        'customer_uuid'        => null,
        'customer_type'        => null,
        'tracking_number_uuid' => null,
        'deleted_at'           => null,
        'meta'                 => json_encode(array_merge(['storefront_network_id' => 'network_public'], $meta)),
    ], $attributes);
    $connection->table('orders')->insert([
        $order(['public_id' => 'order_one', 'customer_uuid' => 'customer_one', 'customer_type' => Contact::class, 'tracking_number_uuid' => 'tracking_uuid', 'created_at' => '2026-10-13 12:00:00'], ['storefront_id' => 'store_public', 'storefront' => 'Corner Store', 'checkout_id' => 'checkout_a', 'total' => 100, 'tip' => 5, 'delivery_tip' => 2, 'currency' => 'USD']),
        $order(['public_id' => 'order_two', 'status' => 'completed', 'customer_uuid' => 'customer_one', 'customer_type' => Contact::class, 'created_at' => '2026-10-12 12:00:00'], ['storefront_id' => 'store_third', 'storefront' => 'Third Store', 'checkout_id' => 'checkout_a', 'total' => 50]),
        $order(['public_id' => 'order_three', 'status' => 'canceled', 'customer_uuid' => 'customer_two', 'customer_type' => Contact::class, 'created_at' => '2026-10-11 12:00:00'], ['storefront_id' => 'store_public', 'total' => 30]),
        $order(['public_id' => 'order_old', 'customer_uuid' => 'customer_three', 'created_at' => '2026-09-01 12:00:00'], ['storefront_id' => 'store_public', 'total' => 999]),
        $order(['public_id' => 'order_other_network', 'customer_uuid' => 'customer_four', 'created_at' => '2026-10-13 12:00:00'], ['storefront_network_id' => 'network_elsewhere', 'total' => 500]),
        $order(['public_id' => 'order_deleted', 'customer_uuid' => 'customer_five', 'created_at' => '2026-10-13 12:00:00', 'deleted_at' => '2026-10-13 13:00:00'], ['storefront_id' => 'store_public', 'total' => 500]),
    ]);

    $data = (new NetworkController())->overview('network_public')->getData(true);

    expect($data['currency'])->toBe('MNT')
        ->and($data['stores'])->toBe(['total' => 3, 'online' => 2, 'pending' => 1])
        ->and($data['invitations'])->toBe(['pending' => 1, 'declined' => 1, 'expired' => 0])
        ->and($data['orders'])->toBe(['count_7d' => 2, 'multi_store_7d' => 1, 'canceled_7d' => 1])
        ->and($data['revenue'])->toEqual(['total_7d' => 150, 'tips_7d' => 7, 'currency' => 'MNT'])
        ->and($data['customers'])->toBe(['total' => 3, 'new_this_month' => 2]);

    $members = collect($data['members'])->keyBy('uuid');
    expect($members['store_uuid'])->toMatchArray([
        'public_id'         => 'store_public',
        'name'              => 'Corner Store',
        'online'            => true,
        'currency'          => 'MNT',
        'category_uuid'     => 'category_uuid',
        'category'          => 'Food',
        'membership_status' => 'active',
        'orders_7d'         => 1,
        'share'             => 50,
    ])
        ->and($members['store_uuid']['revenue_7d'])->toEqual(100)
        ->and($members['store_uuid']['revenue_share'])->toEqual(67)
        ->and($members['second_store_uuid'])->toMatchArray(['membership_status' => 'pending', 'category' => null, 'orders_7d' => 0, 'online' => false])
        ->and($members['second_store_uuid']['share'])->toEqual(0)
        ->and($members['third_store_uuid']['revenue_share'])->toEqual(33);

    expect(collect($data['recent_orders'])->pluck('public_id')->all())->toBe(['order_one', 'order_two', 'order_three'])
        ->and($data['recent_orders'][0])->toMatchArray([
            'tracking'           => 'TRK-1',
            'checkout_id'        => 'checkout_a',
            'customer_name'      => 'Bat',
            'customer_id'        => 'contact_one',
            'customer_photo_url' => 'https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png',
            'store_id'           => 'store_public',
            'store_name'         => 'Corner Store',
            'store_logo_url'     => 'https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png',
            'total'              => 100,
            'currency'           => 'USD',
            'status'             => 'created',
        ])
        ->and($data['recent_orders'][1]['currency'])->toBe('MNT')
        ->and($data['recent_orders'][1]['tracking'])->toBeNull();
});

test('a network with no members or orders has an empty overview', function () {
    $data = (new NetworkController())->overview('network_uuid')->getData(true);

    expect($data['stores'])->toBe(['total' => 0, 'online' => 0, 'pending' => 0])
        ->and($data['orders'])->toBe(['count_7d' => 0, 'multi_store_7d' => 0, 'canceled_7d' => 0])
        ->and($data['customers'])->toBe(['total' => 0, 'new_this_month' => 0])
        ->and($data['members'])->toBe([])
        ->and($data['recent_orders'])->toBe([]);
});

test('updating the shareable link sets its expiry and review setting and regenerates its token on request', function () {
    $controller = new NetworkController();

    $enabled = $controller->updateShareableLink('network_uuid', networkCoverageRequest(['enabled' => true, 'expires_in_days' => 10, 'requires_approval' => false]))->getData(true);
    $token   = $enabled['link']['token'];

    $kept        = $controller->updateShareableLink('network_uuid', networkCoverageRequest(['expires_in_days' => 0]))->getData(true);
    $regenerated = $controller->updateShareableLink('network_uuid', networkCoverageRequest(['regenerate' => true, 'requires_approval' => true]))->getData(true);

    expect($enabled['link'])->toMatchArray(['enabled' => true, 'requires_approval' => false, 'expires_at' => Carbon::parse('2026-10-24 12:00:00')->toIso8601String(), 'joins' => 0])
        ->and($token)->toBeString()->toHaveLength(24)
        ->and($kept['link']['token'])->toBe($token)
        ->and($kept['link']['expires_at'])->toBeNull()
        ->and($kept['link']['requires_approval'])->toBeFalse()
        ->and($regenerated['link']['token'])->not->toBe($token)
        ->and($regenerated['link']['requires_approval'])->toBeTrue()
        ->and(Network::where('uuid', 'network_uuid')->first()->options['shareable_link_token'])->toBe($regenerated['link']['token']);
});

test('approving stores activates their pending memberships and closes their invitations as accepted', function () {
    $connection = Capsule::connection('mysql');
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_uuid', 'status' => 'pending'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'third_store_uuid', 'status' => 'pending'],
    ]);
    networkCoverageInvite(['uuid' => 'awaiting_uuid', 'meta' => json_encode(['status' => 'awaiting_approval', 'accepted_store' => 'store_uuid'])]);
    networkCoverageInvite(['uuid' => 'third_uuid', 'meta' => json_encode(['status' => 'awaiting_approval', 'accepted_store' => 'third_store_uuid'])]);
    networkCoverageInvite(['uuid' => 'accepted_uuid', 'meta' => json_encode(['status' => 'accepted', 'accepted_store' => 'store_uuid'])]);

    $response = (new NetworkController())->approveStores('network_uuid', networkCoverageRequest(['stores' => ['store_uuid']]));

    expect($response->getData(true))->toBe(['status' => 'ok', 'updated' => 1])
        ->and($connection->table('network_stores')->where('store_uuid', 'store_uuid')->value('status'))->toBe('active')
        ->and($connection->table('network_stores')->where('store_uuid', 'third_store_uuid')->value('status'))->toBe('pending')
        ->and(networkCoverageInviteMeta('awaiting_uuid'))->toBe(['status' => 'accepted', 'accepted_store' => 'store_uuid', 'approved_at' => Carbon::now()->toIso8601String()])
        ->and(networkCoverageInviteMeta('third_uuid')['status'])->toBe('awaiting_approval')
        ->and(networkCoverageInviteMeta('accepted_uuid'))->toBe(['status' => 'accepted', 'accepted_store' => 'store_uuid']);
});

test('rejecting stores removes only pending memberships and closes their invitations as rejected', function () {
    $connection = Capsule::connection('mysql');
    $connection->table('network_stores')->insert([
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_uuid', 'status' => 'pending'],
        ['network_uuid' => 'network_uuid', 'store_uuid' => 'third_store_uuid', 'status' => 'active'],
    ]);
    networkCoverageInvite(['uuid' => 'awaiting_uuid', 'meta' => json_encode(['status' => 'awaiting_approval', 'accepted_store' => 'store_uuid'])]);

    $response = (new NetworkController())->rejectStores('network_uuid', networkCoverageRequest(['stores' => ['store_uuid', 'third_store_uuid']]));

    expect($response->getData(true))->toBe(['status' => 'ok', 'removed' => 1])
        ->and($connection->table('network_stores')->where('store_uuid', 'store_uuid')->value('deleted_at'))->not->toBeNull()
        ->and($connection->table('network_stores')->where('store_uuid', 'third_store_uuid')->value('deleted_at'))->toBeNull()
        ->and(networkCoverageInviteMeta('awaiting_uuid'))->toBe(['status' => 'rejected', 'accepted_store' => 'store_uuid', 'rejected_at' => Carbon::now()->toIso8601String()]);
});

test('an expired invitation is still listed, counted, looked up, resent and revoked', function () {
    $mail = networkCoverageMail();
    networkCoverageInvite(['uuid' => 'expired_uuid', 'public_id' => 'invite_expired', 'uri' => 'expired-code', 'recipients' => json_encode(['store@example.test']), 'expires_at' => '2026-10-13 12:00:00']);
    networkCoverageInvite(['uuid' => 'revoked_uuid', 'public_id' => 'invite_revoked', 'recipients' => json_encode(['gone@example.test']), 'expires_at' => '2026-10-13 12:00:00']);
    $controller = new NetworkController();

    // Core hides expired invitations from every query; the network does not.
    $listed   = collect($controller->invitations('network_uuid')->getData(true))->keyBy('uuid');
    $overview = $controller->overview('network_public')->getData(true);
    $lookup   = $controller->lookupInvitation('expired-code');
    $revoked  = $controller->revokeInvitation('network_uuid', 'invite_revoked');
    $resent   = $controller->resendInvitation('network_uuid', 'invite_expired', networkCoverageRequest());

    expect($listed->keys()->sort()->values()->all())->toBe(['expired_uuid', 'revoked_uuid'])
        ->and($listed['expired_uuid']['status'])->toBe('expired')
        ->and($overview['invitations'])->toBe(['pending' => 0, 'declined' => 0, 'expired' => 2])
        ->and($lookup->getStatusCode())->toBe(200)
        ->and($lookup->getData(true)['invitation']['status'])->toBe('expired')
        ->and($revoked->getData(true))->toBe(['status' => 'ok'])
        ->and(Capsule::connection('mysql')->table('invites')->where('uuid', 'revoked_uuid')->value('deleted_at'))->not->toBeNull()
        // Resending reopens it for another fourteen days.
        ->and($resent->getStatusCode())->toBe(200)
        ->and($resent->getData(true)['invitation']['status'])->toBe('pending')
        ->and(Capsule::connection('mysql')->table('invites')->where('uuid', 'expired_uuid')->value('expires_at'))->toBe('2026-10-28 12:00:00')
        ->and($mail->sent)->toHaveCount(1);
});

test('an expired invitation cannot be accepted or declined', function () {
    networkCoverageInvite(['uuid' => 'expired_uuid', 'uri' => 'expired-code', 'expires_at' => '2026-10-13 12:00:00']);
    $controller = new NetworkController();

    $accept  = $controller->acceptInvitation('expired-code', Request::create('/', 'POST', ['store' => 'store_uuid']));
    $decline = $controller->declineInvitation('expired-code');

    expect($accept->getStatusCode())->toBe(404)
        ->and($decline->getStatusCode())->toBe(404)
        ->and(networkCoverageInviteMeta('expired_uuid'))->toBe(['status' => 'pending'])
        ->and(Capsule::connection('mysql')->table('network_stores')->count())->toBe(0);
});
