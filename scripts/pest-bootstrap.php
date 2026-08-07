<?php

declare(strict_types=1);

$autoloadCandidates = [
    getcwd() . '/server_vendor/autoload.php',
    getcwd() . '/vendor/autoload.php',
];

foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        break;
    }
}

if (!class_exists('PhpOption\Option')) {
    eval('namespace PhpOption; class Option { public function __construct(private mixed $value) {} public static function fromValue(mixed $value): self { return new self($value); } public function map(callable $callback): self { return $this->value === null ? $this : new self($callback($this->value)); } public function getOrCall(callable $default): mixed { return $this->value ?? $default(); } public function getOrThrow(\Throwable $exception): mixed { if ($this->value === null) { throw $exception; } return $this->value; } }');
}

if (!function_exists('config')) {
    function config(array|string|null $key = null, mixed $default = null): mixed
    {
        static $values = [];

        if (is_array($key)) {
            foreach ($key as $configKey => $value) {
                Illuminate\Support\Arr::set($values, $configKey, $value);
            }

            return null;
        }

        if ($key === null) {
            return $values;
        }

        return Illuminate\Support\Arr::get($values, $key, $default);
    }
}

if (!function_exists('logger')) {
    function logger(): mixed
    {
        return Illuminate\Container\Container::getInstance()->make('log');
    }
}

if (class_exists('Illuminate\Container\Container') && class_exists('Illuminate\Support\Facades\Facade')) {
    $app = Illuminate\Container\Container::getInstance();

    if (get_class($app) === Illuminate\Container\Container::class) {
        if (!class_exists('Fleetbase\TestSupport\ApplicationContainer')) {
            eval('namespace Fleetbase\TestSupport; class ApplicationContainer extends \Illuminate\Container\Container { public function environment(...$environments): string|bool { if ($environments === []) { return "testing"; } return in_array("testing", $environments, true); } public function isProduction(): bool { return false; } public function hasDebugModeEnabled(): bool { return true; } }');
        }

        $app = new Fleetbase\TestSupport\ApplicationContainer();
        Illuminate\Container\Container::setInstance($app);
    }

    Illuminate\Support\Facades\Facade::setFacadeApplication($app);

    if (!$app->bound('http') && class_exists('Illuminate\Http\Client\Factory')) {
        $app->singleton('http', fn () => new Illuminate\Http\Client\Factory());
    }

    if (!$app->bound('log') && class_exists('Psr\Log\NullLogger')) {
        if (!class_exists('Fleetbase\TestSupport\LoggerManager')) {
            eval('namespace Fleetbase\TestSupport; class LoggerManager extends \Psr\Log\NullLogger { public function channel(?string $name = null): self { return $this; } }');
        }

        $app->singleton('log', fn () => new Fleetbase\TestSupport\LoggerManager());
    }

    if (
        !$app->bound('cache')
        && class_exists('Illuminate\Cache\Repository')
        && class_exists('Illuminate\Cache\ArrayStore')
    ) {
        $app->singleton('cache', fn () => new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    }

    if (!$app->bound('hash')) {
        if (!class_exists('Fleetbase\TestSupport\PasswordHasher')) {
            eval('namespace Fleetbase\TestSupport; class PasswordHasher { public function check(mixed $value, mixed $hashedValue, array $options = []): bool { return is_string($value) && is_string($hashedValue) && password_verify($value, $hashedValue); } public function make(mixed $value, array $options = []): string { return password_hash((string) $value, PASSWORD_BCRYPT); } public function needsRehash(mixed $hashedValue, array $options = []): bool { return password_needs_rehash((string) $hashedValue, PASSWORD_BCRYPT); } }');
        }

        $app->singleton('hash', fn () => new Fleetbase\TestSupport\PasswordHasher());
    }

    if (!$app->bound('request') && class_exists('Illuminate\Http\Request')) {
        $request = Illuminate\Http\Request::create('/');

        if (class_exists('Illuminate\Session\Store') && class_exists('Illuminate\Session\ArraySessionHandler')) {
            $request->setLaravelSession(new Illuminate\Session\Store(
                'storefront-tests',
                new Illuminate\Session\ArraySessionHandler(120)
            ));
        }

        $app->instance('request', $request);
    }

    if (!$app->bound('response') && class_exists('Illuminate\Http\JsonResponse')) {
        if (!class_exists('Fleetbase\TestSupport\ResponseFactory')) {
            eval('namespace Fleetbase\TestSupport; class ResponseFactory { public function json(mixed $data = [], int $status = 200, array $headers = [], int $options = 0): \Illuminate\Http\JsonResponse { return new \Illuminate\Http\JsonResponse($data, $status, $headers, $options); } public function error(string $message, int $status = 400): \Illuminate\Http\JsonResponse { return $this->json(["error" => $message], $status); } public function apiError(string $message, int $status = 400): \Illuminate\Http\JsonResponse { return $this->error($message, $status); } }');
        }

        $app->instance('response', new Fleetbase\TestSupport\ResponseFactory());
    }

    if (
        !$app->bound('validator')
        && class_exists('Illuminate\Validation\Factory')
        && class_exists('Illuminate\Translation\Translator')
        && class_exists('Illuminate\Translation\ArrayLoader')
    ) {
        $translator = new Illuminate\Translation\Translator(
            new Illuminate\Translation\ArrayLoader(),
            'en'
        );
        $app->singleton('validator', fn () => new Illuminate\Validation\Factory($translator, $app));
    }

    if (!$app->bound('validator') && !class_exists('Illuminate\Validation\Factory')) {
        if (!class_exists('Fleetbase\TestSupport\ValidatorFactory')) {
            eval('namespace Fleetbase\TestSupport; class ValidatorFactory { public function make(array $data, array $rules = [], array $messages = [], array $attributes = []): Validator { return new Validator(); } } class Validator { public function fails(): bool { return false; } public function errors(): \Illuminate\Support\MessageBag { return new \Illuminate\Support\MessageBag(); } }');
        }

        $app->singleton('validator', fn () => new Fleetbase\TestSupport\ValidatorFactory());
    }
}

if (
    class_exists('Illuminate\Database\Capsule\Manager')
    && class_exists('Illuminate\Database\Eloquent\Model')
    && Illuminate\Database\Eloquent\Model::getConnectionResolver() === null
) {
    $database = new Illuminate\Database\Capsule\Manager();

    foreach (['mysql', 'fleetbase', 'fleetops', 'storefront'] as $connection) {
        $database->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ], $connection);
    }

    $database->getDatabaseManager()->setDefaultConnection('mysql');
    $database->setAsGlobal();
    $database->bootEloquent();

    if (class_exists('Illuminate\Container\Container')) {
        $container = Illuminate\Container\Container::getInstance();
        $container->instance('db', $database->getDatabaseManager());
        $container->instance('db.schema', $database->getDatabaseManager()->connection()->getSchemaBuilder());
    }
}

if (class_exists('Illuminate\Database\Eloquent\Builder')) {
    Illuminate\Database\Eloquent\Builder::macro('orderByDistance', function (): mixed {
        return $this;
    });
}

if (class_exists('Illuminate\Database\Query\Builder')) {
    Illuminate\Database\Query\Builder::macro('orderByDistance', function (): mixed {
        return $this;
    });
}

if (!function_exists('app')) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        if (class_exists('Illuminate\Container\Container')) {
            $container = Illuminate\Container\Container::getInstance();

            return $abstract === null ? $container : $container->make($abstract, $parameters);
        }

        return $abstract === null ? null : new $abstract(...array_values($parameters));
    }
}

if (!function_exists('request')) {
    function request(?string $key = null, mixed $default = null): mixed
    {
        $request = class_exists('Illuminate\Container\Container')
            && Illuminate\Container\Container::getInstance()->bound('request')
            ? Illuminate\Container\Container::getInstance()->make('request')
            : (class_exists('Illuminate\Http\Request') ? Illuminate\Http\Request::create('/') : new stdClass());

        return $key === null || !method_exists($request, 'input') ? $request : $request->input($key, $default);
    }
}

if (!function_exists('session')) {
    function session(array|string|null $key = null, mixed $default = null): mixed
    {
        static $values = [];

        if (is_array($key)) {
            $values = array_merge($values, $key);

            return null;
        }

        return $key === null ? $values : ($values[$key] ?? $default);
    }
}

if (!function_exists('response')) {
    function response(mixed $content = null, int $status = 200, array $headers = []): mixed
    {
        $factory = app('response');

        return $content === null ? $factory : $factory->json($content, $status, $headers);
    }
}

if (!function_exists('url')) {
    function url(?string $path = null, array $parameters = [], ?bool $secure = null): string
    {
        $base = $secure === false ? 'http://localhost' : 'https://localhost';
        $url  = $path ? $base . '/' . ltrim($path, '/') : $base;

        return $parameters ? $url . '?' . http_build_query($parameters) : $url;
    }
}

if (!function_exists('dispatch')) {
    function dispatch(mixed $job): mixed
    {
        if ($job instanceof Closure) {
            return new class($job) {
                public function __construct(private Closure $job)
                {
                }

                public function afterCommit(): static
                {
                    return $this;
                }
            };
        }

        return $job;
    }
}

if (!function_exists('event')) {
    function event(mixed $event): mixed
    {
        return $event;
    }
}

if (!function_exists('now') && class_exists('Illuminate\Support\Carbon')) {
    function now($tz = null): Illuminate\Support\Carbon
    {
        return Illuminate\Support\Carbon::now($tz);
    }
}

if (!trait_exists('Illuminate\Foundation\Auth\Access\AuthorizesRequests')) {
    eval('namespace Illuminate\Foundation\Auth\Access; trait AuthorizesRequests {}');
}

if (!class_exists('Illuminate\Foundation\Auth\User') && class_exists('Illuminate\Database\Eloquent\Model')) {
    eval('namespace Illuminate\Foundation\Auth; class User extends \Illuminate\Database\Eloquent\Model {}');
}

if (!trait_exists('Illuminate\Foundation\Bus\Dispatchable')) {
    eval('namespace Illuminate\Foundation\Bus; trait Dispatchable {}');
}

if (!trait_exists('Illuminate\Foundation\Bus\DispatchesJobs')) {
    eval('namespace Illuminate\Foundation\Bus; trait DispatchesJobs {}');
}

if (!trait_exists('Illuminate\Foundation\Events\Dispatchable')) {
    eval('namespace Illuminate\Foundation\Events; trait Dispatchable {}');
}

if (!trait_exists('Illuminate\Foundation\Validation\ValidatesRequests')) {
    eval('namespace Illuminate\Foundation\Validation; trait ValidatesRequests {}');
}

if (!class_exists('Illuminate\Foundation\Http\FormRequest') && class_exists('Illuminate\Http\Request')) {
    eval('namespace Illuminate\Foundation\Http; class FormRequest extends \Illuminate\Http\Request { public function authorize() { return true; } public function rules() { return []; } public function responseWithErrors(\Illuminate\Contracts\Validation\Validator $validator) { return $validator; } }');
}

if (!class_exists('Illuminate\Validation\Rules\RequiredIf')) {
    eval('namespace Illuminate\Validation\Rules; class RequiredIf { public function __construct(private mixed $condition) {} public function __toString(): string { return (bool) value($this->condition) ? "required" : ""; } }');
}

if (!class_exists('Illuminate\Validation\Rules\Unique')) {
    eval('namespace Illuminate\Validation\Rules; class Unique { private array $callbacks = []; public function __construct(public string $table, public string $column = "NULL") {} public function where(callable $callback): self { $this->callbacks[] = $callback; return $this; } public function queryCallbacks(): array { return $this->callbacks; } public function __toString(): string { return "unique:{$this->table},{$this->column}"; } }');
}

if (!class_exists('Illuminate\Validation\Rule')) {
    eval('namespace Illuminate\Validation; class Rule { public static function requiredIf(mixed $condition): \Illuminate\Validation\Rules\RequiredIf { return new \Illuminate\Validation\Rules\RequiredIf($condition); } public static function unique(string $table, string $column = "NULL"): \Illuminate\Validation\Rules\Unique { return new \Illuminate\Validation\Rules\Unique($table, $column); } }');
}

if (class_exists('Illuminate\Support\Arr') && !Illuminate\Support\Arr::hasMacro('insertAfterKey')) {
    Illuminate\Support\Arr::macro('insertAfterKey', function (array $array = [], array $items = [], string|int $key = 0): array {
        $position = array_search($key, array_keys($array), true);

        if ($position === false) {
            return $array + $items;
        }

        $position++;

        return array_slice($array, 0, $position, true)
            + $items
            + array_slice($array, $position, null, true);
    });
}

if (class_exists('Illuminate\Http\Request') && !Illuminate\Http\Request::hasMacro('inArray')) {
    Illuminate\Http\Request::macro('inArray', function (string $parameter, mixed $needle): bool {
        return in_array($needle, (array) $this->input($parameter, []), true);
    });

    Illuminate\Http\Request::macro('isArray', function (string $parameter): bool {
        return $this->has($parameter) && is_array($this->input($parameter));
    });
}

if (class_exists('Illuminate\Http\Request') && !Illuminate\Http\Request::hasMacro('array')) {
    Illuminate\Http\Request::macro('array', function (string $parameter, array $default = []): array {
        return (array) $this->input($parameter, $default);
    });
}

if (class_exists('Illuminate\Http\Request') && !Illuminate\Http\Request::hasMacro('or')) {
    Illuminate\Http\Request::macro('or', function (array $parameters, mixed $default = null): mixed {
        foreach ($parameters as $parameter) {
            if ($this->filled($parameter)) {
                return $this->input($parameter);
            }
        }

        return $default;
    });
}

if (!interface_exists('Fleetbase\Ai\Contracts\AIContextCapabilityInterface')) {
    eval('namespace Fleetbase\Ai\Contracts; interface AIContextCapabilityInterface {}');
}

if (!interface_exists('Fleetbase\Ai\Contracts\AIActionCapabilityInterface')) {
    eval('namespace Fleetbase\Ai\Contracts; interface AIActionCapabilityInterface {}');
}

if (!class_exists('Fleetbase\Ai\Models\AiTask')) {
    eval('namespace Fleetbase\Ai\Models; class AiTask { public function __construct(array $attributes = []) { foreach ($attributes as $key => $value) { $this->{$key} = $value; } } }');
}

if (!class_exists('Fleetbase\Support\SocketCluster\SocketClusterService', false)) {
    eval('namespace Fleetbase\Support\SocketCluster; class SocketClusterService { public static array $published = []; public static function publish(string $channel, mixed $data): bool { static::$published[] = [$channel, $data]; return true; } }');
}

if (!class_exists('Fleetbase\Ai\Support\Capabilities\AbstractAICapability')) {
    eval('namespace Fleetbase\Ai\Support\Capabilities; abstract class AbstractAICapability {}');
}

if (!class_exists('Fleetbase\Ai\Support\AiQueryableResource')) {
    eval('namespace Fleetbase\Ai\Support; class AiQueryableResource { public string $key; public array $fields; public array $aliases; public function __construct(string $key, string $label = "", string $module = "", string $modelClass = "", string $permission = "", array $aliases = [], array $fields = [], array $sampleFields = [], ?string $locationField = null, ?string $directivePermission = null, int $maxLimit = 100) { $this->key = $key; $this->fields = $fields; $this->aliases = $aliases; } public function hasField(string $field): bool { return array_key_exists($field, $this->fields); } }');
}

if (!class_exists('Fleetbase\Ai\Support\AiQueryRegistry')) {
    eval('namespace Fleetbase\Ai\Support; class AiQueryRegistry { private array $resources = []; public function register(AiQueryableResource $resource): void { $this->resources[$resource->key] = $resource; foreach ($resource->aliases as $alias) { $this->resources[$alias] = $resource; } } public function find(string $key): ?AiQueryableResource { return $this->resources[$key] ?? null; } }');
}

if (!class_exists('Fleetbase\Ai\Support\AiRelativeDateResolver') && class_exists('Illuminate\Support\Carbon')) {
    eval('namespace Fleetbase\Ai\Support; class AiRelativeDateResolver { public function __construct($parser = null) {} public function resolveDateTime(string $prompt, ?string $timezone = null): ?\Illuminate\Support\Carbon { if (preg_match("/(\d+)\s+days?\s+from\s+now/i", $prompt, $matches)) { return \Illuminate\Support\Carbon::now($timezone)->addDays((int) $matches[1]); } return null; } public function resolveWindow(string $prompt, ?string $timezone = null): ?array { $timezone = $timezone ?: date_default_timezone_get(); $now = \Illuminate\Support\Carbon::now($timezone); if (str_contains(strtolower($prompt), "last week")) { $start = $now->copy()->subWeek()->startOfWeek(); $end = $now->copy()->subWeek()->endOfWeek(); return ["label" => "last week", "timezone" => $timezone, "start" => $start, "end" => $end]; } if (str_contains(strtolower($prompt), "yesterday")) { $start = $now->copy()->subDay()->startOfDay(); $end = $now->copy()->subDay()->endOfDay(); return ["label" => "yesterday", "timezone" => $timezone, "start" => $start, "end" => $end]; } return null; } }');
}

if (!class_exists('Fleetbase\Support\Auth', false)) {
    eval('namespace Fleetbase\Support; class Auth { public static mixed $user = null; public static array $permissions = []; public static function getUserFromSession(): mixed { return static::$user; } public static function can(string $permission): bool { return in_array($permission, static::$permissions, true); } public static function getDirectivesFromRequest(\Illuminate\Http\Request $request): \Illuminate\Support\Collection { return collect(); } }');
}

set_error_handler(function (int $severity, string $message): bool {
    if (str_contains($message, '/pestphp/pest/vendor/autoload.php')) {
        return true;
    }

    return false;
}, E_WARNING);
