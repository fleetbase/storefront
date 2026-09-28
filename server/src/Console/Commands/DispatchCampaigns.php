<?php

namespace Fleetbase\Storefront\Console\Commands;

use Fleetbase\Storefront\Promotions\CampaignDispatcher;
use Illuminate\Console\Command;

class DispatchCampaigns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storefront:dispatch-campaigns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send scheduled storefront campaigns that are due';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(CampaignDispatcher $dispatcher)
    {
        $sent = $dispatcher->dispatchDue();

        $this->info('Dispatched ' . $sent . ' campaign(s).');

        return 0;
    }
}
