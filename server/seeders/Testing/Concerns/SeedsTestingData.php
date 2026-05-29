<?php

namespace Fleetbase\Storefront\Seeders\Testing\Concerns;

use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Model as FleetbaseModel;
use Fleetbase\Models\User;
use Fleetbase\Seeders\Concerns\ResolvesSeedCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait SeedsTestingData
{
    use ResolvesSeedCompany;

    protected const SEED_NAME = 'storefront-testing';

    protected function resolveCompany(): ?Company
    {
        return $this->resolveSeedCompany();
    }

    protected function prepareCompany(): ?Company
    {
        $company = $this->resolveCompany();
        if (!$company) {
            $this->command?->error('No company found. Create a Fleetbase company before running Storefront testing seeders.');

            return null;
        }

        session(['company' => $company->uuid]);

        $user = $this->resolveUser($company);
        if ($user) {
            session(['user' => $user->uuid]);
        }

        return $company;
    }

    protected function resolveUser(Company $company): ?User
    {
        if (Str::isUuid($company->owner_uuid)) {
            $owner = User::where('uuid', $company->owner_uuid)->first();
            if ($owner) {
                return $owner;
            }
        }

        $companyUser = CompanyUser::where('company_uuid', $company->uuid)->first();
        if ($companyUser) {
            return User::where('uuid', $companyUser->user_uuid)->first();
        }

        return User::query()->orderBy('created_at')->first();
    }

    protected function fixtureKey(string $seedId): string
    {
        return static::SEED_NAME . ':' . $seedId;
    }

    protected function meta(string $seedId, array $extra = []): array
    {
        return array_merge([
            'seed'    => static::SEED_NAME,
            'seed_id' => $seedId,
        ], $extra);
    }

    protected function timestamp(int $hoursOffset = 0): Carbon
    {
        $now       = Carbon::now($this->seedTimezone());
        $timestamp = $now->copy()->startOfMonth()->addHours(8 + $hoursOffset);

        if ($timestamp->greaterThan($now)) {
            return $now;
        }

        return $timestamp;
    }

    protected function seedTimezone(): string
    {
        return config('app.timezone') ?: 'UTC';
    }

    protected function createRecord(string $modelClass, array $attributes, bool $withoutEvents = false): Model
    {
        /** @var Model $model */
        $model      = new $modelClass();
        $attributes = $this->filterColumns($model, array_merge([
            'uuid'       => (string) Str::uuid(),
            'created_at' => $this->timestamp(),
            'updated_at' => $this->timestamp(),
        ], $attributes));

        $model->forceFill($attributes);

        if ($withoutEvents) {
            $modelClass::withoutEvents(fn () => $model->save());
        } else {
            $model->save();
        }

        return $model;
    }

    protected function filterColumns(Model $model, array $attributes): array
    {
        $table      = $model->getTable();
        $connection = $model->getConnectionName();

        if (!Schema::connection($connection)->hasTable($table)) {
            return $attributes;
        }

        return array_filter(
            $attributes,
            fn (string $column) => Schema::connection($connection)->hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    protected function seededQuery(string $modelClass)
    {
        /** @var FleetbaseModel|Model $model */
        $model      = new $modelClass();
        $table      = $model->getTable();
        $connection = $model->getConnectionName();
        $query      = $modelClass::query();

        if (Schema::connection($connection)->hasColumn($table, 'meta')) {
            return $query->where('meta->seed', static::SEED_NAME);
        }

        if (Schema::connection($connection)->hasColumn($table, 'options')) {
            return $query->where('options->seed', static::SEED_NAME);
        }

        if (Schema::connection($connection)->hasColumn($table, '_key')) {
            return $query->where('_key', 'like', static::SEED_NAME . ':%');
        }

        if (Schema::connection($connection)->hasColumn($table, 'unique_identifier')) {
            return $query->where('unique_identifier', 'like', static::SEED_NAME . ':%');
        }

        return $query->whereRaw('1 = 0');
    }

    protected function seededUuids(string $modelClass): array
    {
        return $this->seededQuery($modelClass)->pluck('uuid')->filter()->values()->all();
    }

    protected function seededModel(string $modelClass, string $seedId): ?Model
    {
        /** @var Model $model */
        $model      = new $modelClass();
        $table      = $model->getTable();
        $connection = $model->getConnectionName();

        if (Schema::connection($connection)->hasColumn($table, 'meta')) {
            return $modelClass::where('meta->seed', static::SEED_NAME)->where('meta->seed_id', $seedId)->first();
        }

        if (Schema::connection($connection)->hasColumn($table, 'options')) {
            return $modelClass::where('options->seed', static::SEED_NAME)->where('options->seed_id', $seedId)->first();
        }

        if (Schema::connection($connection)->hasColumn($table, '_key')) {
            return $modelClass::where('_key', $this->fixtureKey($seedId))->first();
        }

        if (Schema::connection($connection)->hasColumn($table, 'unique_identifier')) {
            return $modelClass::where('unique_identifier', $this->fixtureKey($seedId))->first();
        }

        return null;
    }

    protected function purgeModel(string $modelClass): void
    {
        /** @var Model $model */
        $model = new $modelClass();
        $query = $this->seededQuery($modelClass);

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true) || method_exists($model, 'bootSoftDeletes')) {
            $query->forceDelete();

            return;
        }

        $query->delete();
    }

    protected function deleteFrom(string $connection, string $table, callable $callback): void
    {
        if (!Schema::connection($connection)->hasTable($table)) {
            return;
        }

        $query = DB::connection($connection)->table($table);
        $callback($query);
        $query->delete();
    }

    protected function storefrontConnection(): string
    {
        return config('storefront.connection.db');
    }

    protected function fleetbaseConnection(): string
    {
        return config('fleetbase.connection.db');
    }
}
