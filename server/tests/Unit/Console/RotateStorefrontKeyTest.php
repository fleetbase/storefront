<?php

use Fleetbase\Storefront\Console\Commands\RotateStorefrontKey;
use Fleetbase\Storefront\Http\Middleware\SetStorefrontSession;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function createRotateKeyCommandSchema(): void
{
    $schema = Capsule::schema('mysql');

    foreach (['stores', 'networks', 'companies'] as $table) {
        $schema->dropIfExists($table);
        $schema->create($table, function ($blueprint) use ($table) {
            $blueprint->increments('id');
            $blueprint->string('uuid')->nullable();
            $blueprint->string('public_id')->nullable();
            $blueprint->string('name')->nullable();

            if ($table !== 'companies') {
                $blueprint->string('company_uuid')->nullable();
                $blueprint->string('key')->nullable();
            }

            $blueprint->timestamps();
            $blueprint->softDeletes();
        });
    }

    $connection = Capsule::connection('mysql');
    $connection->table('companies')->insert([
        ['uuid' => 'company_uuid', 'public_id' => 'company_public', 'name' => 'Acme'],
        ['uuid' => 'other_company_uuid', 'public_id' => 'company_other', 'name' => 'Other'],
    ]);
    $connection->table('stores')->insert([
        ['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'company_uuid' => 'company_uuid', 'key' => 'store_aaaa', 'name' => 'Store A'],
        ['uuid' => 'store_b_uuid', 'public_id' => 'store_b', 'company_uuid' => 'company_uuid', 'key' => 'store_bbbb', 'name' => 'Store B'],
        ['uuid' => 'store_c_uuid', 'public_id' => 'store_c', 'company_uuid' => 'other_company_uuid', 'key' => 'store_cccc', 'name' => 'Store C'],
    ]);
    $connection->table('networks')->insert([
        ['uuid' => 'network_a_uuid', 'public_id' => 'network_a', 'company_uuid' => 'company_uuid', 'key' => 'network_aaaa', 'name' => 'Network A'],
        ['uuid' => 'network_c_uuid', 'public_id' => 'network_c', 'company_uuid' => 'other_company_uuid', 'key' => 'network_cccc', 'name' => 'Network C'],
    ]);
}

function runRotateKeyCommand(array $input): array
{
    $buffer  = new BufferedOutput();
    $command = new RotateStorefrontKey();
    $command->setInput(new ArrayInput($input, $command->getDefinition()));
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    return [$command->handle(), $buffer->fetch()];
}

function storefrontKeys(): array
{
    $connection = Capsule::connection('mysql');

    return $connection->table('stores')->pluck('key', 'public_id')->merge($connection->table('networks')->pluck('key', 'public_id'))->all();
}

test('the rotate key command rotates stores and networks by uuid, public id or current key and prints old and new keys', function () {
    createRotateKeyCommandSchema();
    $before = storefrontKeys();

    [$status, $output] = runRotateKeyCommand(['id' => ['store_a', 'store_b_uuid', 'network_aaaa'], '--force' => true]);
    $after             = storefrontKeys();

    expect($status)->toBe(Command::SUCCESS)
        ->and($after['store_a'])->toMatch('/^store_[0-9a-f]{32}$/')
        ->and($after['store_b'])->toMatch('/^store_[0-9a-f]{32}$/')
        ->and($after['network_a'])->toMatch('/^network_[0-9a-f]{32}$/')
        ->and($after['store_c'])->toBe($before['store_c'])
        ->and($after['network_c'])->toBe($before['network_c'])
        ->and($output)->toContain('store_aaaa')
        ->and($output)->toContain($after['store_a'])
        ->and($output)->toContain('network_aaaa')
        ->and($output)->toContain($after['network_a'])
        ->and($output)->toContain('Rotated 3 storefront key(s).')
        ->and((new SetStorefrontSession())->isValidKey('store_aaaa'))->toBeFalse();
});

test('the rotate key command rotates every store and network of a company', function (string $company) {
    createRotateKeyCommandSchema();
    $before = storefrontKeys();

    [$status, $output] = runRotateKeyCommand(['--company' => $company, '--force' => true]);
    $after             = storefrontKeys();

    expect($status)->toBe(Command::SUCCESS)
        ->and($after['store_a'])->not->toBe($before['store_a'])
        ->and($after['store_b'])->not->toBe($before['store_b'])
        ->and($after['network_a'])->not->toBe($before['network_a'])
        ->and($after['store_c'])->toBe($before['store_c'])
        ->and($after['network_c'])->toBe($before['network_c'])
        ->and($output)->toContain('Rotated 3 storefront key(s).');
})->with(['company uuid' => 'company_uuid', 'company public id' => 'company_public']);

test('the rotate key command rotates nothing when any id is unknown', function () {
    createRotateKeyCommandSchema();
    $before = storefrontKeys();

    [$status, $output] = runRotateKeyCommand(['id' => ['store_a', 'store_missing'], '--force' => true]);

    expect($status)->toBe(Command::FAILURE)
        ->and($output)->toContain("No store or network found for 'store_missing'.")
        ->and(storefrontKeys())->toBe($before);
});

test('the rotate key command rejects an unknown company and a call without targets', function () {
    createRotateKeyCommandSchema();
    $before = storefrontKeys();

    [$unknownStatus, $unknownOutput] = runRotateKeyCommand(['--company' => 'company_missing', '--force' => true]);
    [$emptyStatus, $emptyOutput]     = runRotateKeyCommand([]);

    expect($unknownStatus)->toBe(Command::FAILURE)
        ->and($unknownOutput)->toContain("Company 'company_missing' not found.")
        ->and($emptyStatus)->toBe(Command::INVALID)
        ->and($emptyOutput)->toContain('Pass one or more store or network ids, or --company.')
        ->and(storefrontKeys())->toBe($before);
});

test('the rotate key command rotates nothing when the confirmation is declined', function () {
    createRotateKeyCommandSchema();
    $before  = storefrontKeys();
    $buffer  = new BufferedOutput();
    $command = new class extends RotateStorefrontKey {
        public function confirm($question, $default = false)
        {
            return false;
        }
    };
    $command->setInput(new ArrayInput(['id' => ['store_a']], $command->getDefinition()));
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    expect($command->handle())->toBe(Command::FAILURE)
        ->and($buffer->fetch())->toContain('No keys were rotated.')
        ->and(storefrontKeys())->toBe($before);
});
