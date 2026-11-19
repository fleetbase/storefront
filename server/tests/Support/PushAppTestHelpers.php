<?php

use Fleetbase\Models\PushApp;
use Fleetbase\Push\PushCredentials;
use Fleetbase\Push\PushMessage;
use Fleetbase\Push\PushOutcome;
use Fleetbase\Push\PushProviders;
use Fleetbase\Push\Transports\ApnTransport;
use Fleetbase\Push\Transports\FcmTransport;
use Illuminate\Database\Eloquent\Model;

/*
 * Shared fixtures for Storefront's push app tests: the push_apps and user_devices tables,
 * an encrypter for the encrypted provider settings, and fake APNs/FCM transports.
 */
if (!class_exists('SfPushAppsTestEncrypter')) {
    /**
     * AES-256-CBC, standing in for the framework's encrypter (not installed with the package).
     */
    class SfPushAppsTestEncrypter implements Illuminate\Contracts\Encryption\Encrypter
    {
        private string $key = 'storefront-push-apps-test-key-32';

        public function encrypt($value, $serialize = true)
        {
            $iv = random_bytes(16);

            return base64_encode($iv . openssl_encrypt($serialize ? serialize($value) : $value, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv));
        }

        public function decrypt($payload, $unserialize = true)
        {
            $raw   = base64_decode($payload);
            $value = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 16));

            return $unserialize ? unserialize($value) : $value;
        }

        public function getKey()
        {
            return $this->key;
        }

        public function getAllKeys()
        {
            return [$this->key];
        }

        public function getPreviousKeys()
        {
            return [];
        }
    }

    class SfPushAppsFakeApn extends ApnTransport
    {
        public array $calls = [];

        /** @var array<string, PushOutcome> by token */
        public array $outcomes = [];

        public function __construct()
        {
        }

        public function send(PushCredentials $credentials, PushMessage $message, array $tokens, ?string $environment = null): array
        {
            $this->calls[] = [$credentials->id, $environment, $tokens, $message->title];

            return array_intersect_key($this->outcomes, array_flip($tokens)) + array_fill_keys($tokens, PushOutcome::sent());
        }
    }

    class SfPushAppsFakeFcm extends FcmTransport
    {
        public array $calls = [];

        public function __construct()
        {
        }

        public function send(PushCredentials $credentials, PushMessage $message, array $tokens, ?string $environment = null): array
        {
            $this->calls[] = [$credentials->id, $tokens, $message->title];

            return array_fill_keys($tokens, PushOutcome::sent());
        }
    }
}

if (!function_exists('sfPushAppsDb')) {
    function sfPushAppsDb()
    {
        return Model::getConnectionResolver()->connection('mysql');
    }

    /**
     * Fresh push_apps and user_devices tables, model events on (uuid, key and secret keeping
     * run in them), the encrypter and fake transports registered as the push providers.
     *
     * @return array{apn: SfPushAppsFakeApn, fcm: SfPushAppsFakeFcm}
     */
    function sfPushAppsSetUp(): array
    {
        $schema = sfPushAppsDb()->getSchemaBuilder();
        foreach (['push_apps', 'user_devices'] as $table) {
            $schema->dropIfExists($table);
        }
        $schema->create('push_apps', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('created_by_uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('key')->nullable();
            $table->text('providers')->nullable();
            $table->string('status')->nullable();
            $table->string('owner_uuid')->nullable();
            $table->string('owner_type')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('user_devices', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('user_uuid')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('push_app_uuid')->nullable();
            $table->string('platform')->nullable();
            $table->string('push_provider')->nullable();
            $table->string('app_identifier')->nullable();
            $table->string('environment')->nullable();
            $table->string('token')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Model::encryptUsing(new SfPushAppsTestEncrypter());
        if (!app()->bound('responsecache')) {
            app()->instance('responsecache', new class {
                public function clear(): void
                {
                }
            });
        }
        $fakes = ['apn' => new SfPushAppsFakeApn(), 'fcm' => new SfPushAppsFakeFcm()];
        app()->instance(PushProviders::class, new PushProviders($fakes));

        return $fakes;
    }

    /**
     * Run the callback with model events dispatched, so PushApp's creating and saving hooks run.
     */
    function sfPushAppsWithEvents(callable $callback): mixed
    {
        Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
        Model::clearBootedModels();

        try {
            return $callback();
        } finally {
            Model::unsetEventDispatcher();
            Model::clearBootedModels();
        }
    }

    function sfPushAppsApn(array $overrides = []): array
    {
        return array_replace(['key_id' => 'KEY1234567', 'team_id' => 'TEAM123456', 'app_bundle_id' => 'com.cornerstore.app', 'private_key_content' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----"], $overrides);
    }

    function sfPushAppsFcm(array $overrides = []): array
    {
        return array_replace(['credentials' => json_encode(['project_id' => 'corner-store', 'client_email' => 'push@corner-store.iam.gserviceaccount.com', 'private_key' => 'secret'])], $overrides);
    }

    /**
     * A push app saved without model events (so its uuid and key are the ones given).
     */
    function sfPushAppsCreate(array $attributes = [], array $providers = []): PushApp
    {
        static $sequence = 0;
        $sequence++;

        $app = new PushApp();
        $app->forceFill(array_replace([
            'uuid'         => 'push_app_uuid_' . $sequence,
            'public_id'    => 'push_app_public_' . $sequence,
            'key'          => 'push_app_key' . $sequence,
            'name'         => 'Corner Store',
            'company_uuid' => 'company_uuid',
            'status'       => 'active',
            'owner_uuid'   => 'store_uuid',
            'owner_type'   => Fleetbase\Storefront\Models\Store::class,
        ], $attributes));
        $app->providers = $providers;
        $app->save();

        return $app;
    }
}
