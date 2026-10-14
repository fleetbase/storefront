<?php

namespace Fleetbase\Storefront\Console\Commands;

use Fleetbase\Models\Company;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RotateStorefrontKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storefront:rotate-key
                            {id?* : Store or network uuids, public ids or current keys}
                            {--company= : Rotate the keys of every store and network of this company (uuid or public_id)}
                            {--force : Rotate without asking for confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Issue stores and networks new storefront keys, so apps using the old keys are rejected';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $ids       = $this->argument('id');
        $companyId = $this->option('company');

        if (empty($ids) && !$companyId) {
            $this->error('Pass one or more store or network ids, or --company.');

            return Command::INVALID;
        }

        // Resolve everything before rotating anything, so a typo never leaves a partial rotation.
        $targets = collect();
        foreach ($ids as $id) {
            $target = $this->findStorefront($id);

            if (!$target) {
                $this->error("No store or network found for '{$id}'.");

                return Command::FAILURE;
            }

            $targets->push($target);
        }

        if ($companyId) {
            $company = Company::where('uuid', $companyId)->orWhere('public_id', $companyId)->first();

            if (!$company) {
                $this->error("Company '{$companyId}' not found.");

                return Command::FAILURE;
            }

            $targets = $targets->merge($this->companyStorefronts($company->uuid));
        }

        $targets = $targets->unique(fn ($target) => get_class($target) . ':' . $target->uuid)->values();

        if ($targets->isEmpty()) {
            $this->warn('There are no stores or networks to rotate.');

            return Command::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("Rotate {$targets->count()} storefront key(s)? Apps using the old keys stop working immediately.")) {
            $this->warn('No keys were rotated.');

            return Command::FAILURE;
        }

        $rows = $targets->map(function ($target) {
            $oldKey = $target->key;
            $newKey = $target->rotateKey();

            return [$target instanceof Network ? 'network' : 'store', $target->public_id, $target->name, $oldKey, $newKey];
        });

        $this->table(['Type', 'ID', 'Name', 'Old key', 'New key'], $rows->all());
        $this->info("Rotated {$rows->count()} storefront key(s).");

        return Command::SUCCESS;
    }

    /**
     * Find a store or network by uuid, public id or current key.
     */
    protected function findStorefront(string $id): Store|Network|null
    {
        $match = fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id)->orWhere('key', $id);

        return Store::where($match)->first() ?? Network::where($match)->first();
    }

    /**
     * Every store and network of a company.
     */
    protected function companyStorefronts(string $companyUuid): Collection
    {
        return Store::where('company_uuid', $companyUuid)->get()
            ->toBase()
            ->merge(Network::where('company_uuid', $companyUuid)->get());
    }
}
