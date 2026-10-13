<?php

namespace Fleetbase\Storefront\Console\Commands;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Support\OrderSettlement;
use Illuminate\Console\Command;

class CheckBalancePayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storefront:check-balance-payments {--days=14 : Only orders updated in the last N days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Settle balances customers paid through their payment link (Stripe has no callback here; QPay callbacks can be missed)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $days    = max(1, (int) $this->option('days'));
        $settled = 0;

        Order::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('meta->balance_due', '>', 0)
            ->whereNotNull('meta->balance_payment->reference')
            ->where('updated_at', '>=', now()->subDays($days))
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$settled) {
                foreach ($orders as $order) {
                    try {
                        session(['company' => $order->company_uuid]);
                        if (OrderSettlement::checkBalancePayment($order)) {
                            $settled++;
                        }
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        $this->info('Settled ' . $settled . ' paid balance(s).');

        return 0;
    }
}
