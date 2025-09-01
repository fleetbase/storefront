<?php

namespace Fleetbase\Storefront\Console\Commands;

use Fleetbase\Storefront\Models\Customer;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Console\Command;
use Stripe\Customer as StripeCustomer;
use Stripe\Exception\InvalidRequestException;
use Stripe\Stripe;

class MigrateStripeSandboxCustomers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Added a --store option to allow running the migration for a single store by UUID or public_id.
     */
    protected $signature = 'storefront:migrate-stripe-customers 
                            {--dry-run : Don\'t actually create or update anything}
                            {--store= : Specify a store UUID or public_id to migrate only that store}';

    /** The console command description. */
    protected $description = 'Migrates Stripe customers created in test mode to live mode and updates contact metadata.';

    /** Execute the console command. */
    public function handle(): int
    {
        $dryRun     = $this->option('dry-run');
        $storeInput = $this->option('store');

        $this->info('Starting Stripe customer migration...');

        // If a single store is specified, load that store by UUID or public_id.
        if ($storeInput) {
            $store = Store::where('uuid', $storeInput)
                ->orWhere('public_id', $storeInput)
                ->first();

            if (!$store) {
                $this->error("Store '{$storeInput}' not found.");

                return Command::FAILURE;
            }

            $this->migrateCustomers($store, $dryRun);
        } else {
            // Otherwise, load all stores.
            $stores = Store::all();
            foreach ($stores as $store) {
                $this->migrateCustomers($store, $dryRun);
            }
        }

        $this->info('Stripe customer migration complete.');

        return Command::SUCCESS;
    }

    /**
     * Migrates all customers for a given store whose stripe_id is still pointing at test mode.
     *
     * Only runs when the store's gateway is configured for live mode; skips migration for sandbox gateways.
     */
    public function migrateCustomers(Store $store, bool $dryRun): int
    {
        // Find the Stripe gateway for this store (there is only one gateway per store).
        $gateway = Gateway::where([
            'code'      => 'stripe',
            'owner_uuid'=> $store->uuid,
        ])->first();

        if (!$gateway) {
            $this->warn("Store {$store->name}: no Stripe gateway configured.");

            return Command::SUCCESS;
        }

        // If the gateway is still sandbox, skip migration because we can't create live customers yet.
        if ($gateway->sandbox) {
            $this->warn("Store {$store->name} is using a sandbox gateway. Migration will only run when the gateway is switched to live.");

            return Command::SUCCESS;
        }

        // Inform the store being migrated
        $this->info("Store {$store->name}: Will have sandbox customers migrated...");

        $secretKey = $gateway->config->secret_key;

        // Process customers in chunks to conserve memory.
        Customer::where('company_uuid', $store->company_uuid)->chunk(50, function ($customers) use ($dryRun, $secretKey, $store) {
            foreach ($customers as $customer) {
                $stripeId = $customer->getMeta('stripe_id');
                if (!$stripeId) {
                    continue;
                }

                // Set the Stripe API key to the store's live secret key.
                Stripe::setApiKey($secretKey);

                $current = null;

                try {
                    // Attempt to retrieve the customer using the current key.
                    // If found and livemode is true, no migration needed.
                    $current = StripeCustomer::retrieve($stripeId);
                    if ($current && $current->livemode === true) {
                        $this->line("Customer {$customer->id}: Stripe ID {$stripeId} is already a live customer.");
                        continue;
                    }
                } catch (InvalidRequestException $e) {
                    // The existing ID was not found with the live key, so it likely belongs to the test environment.
                }

                // If we're doing a dry run, just report what would happen.
                if ($dryRun) {
                    $this->info("Customer {$customer->id}: Would migrate test Stripe ID {$stripeId} to live.");
                    continue;
                }

                // Create a new live customer using the contact details.
                $metadata = [
                    'contact_id'    => $customer->public_id,
                    'storefront_id' => $store->public_id,
                    'company_id'    => $store->company_uuid,
                ];

                $params = [
                    'description' => 'Customer migrated from sandbox',
                    'email'       => $customer->email,
                    'name'        => $customer->name,
                    'phone'       => $customer->phone,
                    'metadata'    => $metadata,
                ];

                $newCustomer = StripeCustomer::create($params);

                // Save the old test ID and update the live ID.
                $customer->updateMeta('stripe_id_sandbox', $stripeId);
                $customer->updateMeta('stripe_id', $newCustomer->id);

                $this->info("Customer {$customer->id}: Migrated test ID {$stripeId} to live Stripe ID {$newCustomer->id}.");
            }
        });

        return Command::SUCCESS;
    }
}
