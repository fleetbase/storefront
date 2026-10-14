<?php

use Fleetbase\Storefront\Console\Commands\RotateStorefrontKey;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

test('the rotate key command reports a company without stores or networks and rotates nothing', function () {
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
        ['uuid' => 'empty_company_uuid', 'public_id' => 'company_empty', 'name' => 'New Co'],
        ['uuid' => 'busy_company_uuid', 'public_id' => 'company_busy', 'name' => 'Busy Co'],
    ]);
    $connection->table('stores')->insert(['uuid' => 'busy_store_uuid', 'public_id' => 'store_busy', 'company_uuid' => 'busy_company_uuid', 'key' => 'store_busy_key', 'name' => 'Busy']);
    $connection->table('networks')->insert(['uuid' => 'busy_network_uuid', 'public_id' => 'network_busy', 'company_uuid' => 'busy_company_uuid', 'key' => 'network_busy_key', 'name' => 'Busy Net']);

    $buffer  = new BufferedOutput();
    $command = new class extends RotateStorefrontKey {
        public bool $asked = false;

        public function confirm($question, $default = false)
        {
            $this->asked = true;

            return true;
        }
    };
    $command->setInput(new ArrayInput(['--company' => 'company_empty'], $command->getDefinition()));
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    $status = $command->handle();

    // Nothing to rotate is not an error, and the operator is not asked to confirm nothing.
    expect($status)->toBe(Command::SUCCESS)
        ->and($buffer->fetch())->toContain('There are no stores or networks to rotate.')
        ->and($command->asked)->toBeFalse()
        ->and($connection->table('stores')->value('key'))->toBe('store_busy_key')
        ->and($connection->table('networks')->value('key'))->toBe('network_busy_key');
});
