<?php

require_once __DIR__ . '/../../Support/OrderSettlementTestHelpers.php';

use Fleetbase\Storefront\Console\Commands\CheckBalancePayments;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Stripe's Checkout Sessions for each payment link: paid, unpaid, or an outage. */
function balanceSweepStripe(array $paidLinks, array $failingLinks = []): Stripe\HttpClient\ClientInterface
{
    $client = new class($paidLinks, $failingLinks) implements Stripe\HttpClient\ClientInterface {
        public array $asked = [];

        public function __construct(private array $paidLinks, private array $failingLinks)
        {
        }

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $path = (string) parse_url($absUrl, PHP_URL_PATH);
            if ($path === '/v1/checkout/sessions') {
                $link          = $params['payment_link'] ?? null;
                $this->asked[] = $link;
                if (in_array($link, $this->failingLinks, true)) {
                    return [json_encode(['error' => ['message' => 'Stripe is unavailable', 'type' => 'api_error']]), 500, []];
                }

                $status = in_array($link, $this->paidLinks, true) ? 'paid' : 'unpaid';

                return [json_encode(['object' => 'list', 'data' => [['id' => 'cs_' . $link, 'object' => 'checkout.session', 'payment_status' => $status, 'amount_total' => 700, 'payment_intent' => 'pi_' . $link]]]), 200, []];
            }

            // Deactivating a paid link.
            return [json_encode(['id' => basename($path), 'object' => 'payment_link', 'active' => false]), 200, []];
        }
    };
    Stripe\ApiRequestor::setHttpClient($client);

    return $client;
}

/** An order with a balance, its checkout, and when it last changed. */
function balanceSweepOrder(string $id, array $meta, string $updatedAt, ?string $deletedAt = null): void
{
    settlementCoverageDb()->table('orders')->insert([
        'uuid'         => $id . '_uuid',
        'public_id'    => $id,
        'company_uuid' => 'company_' . $id,
        'status'       => 'completed',
        'meta'         => json_encode(array_merge(['total' => 5000, 'currency' => 'USD', 'gateway' => 'stripe', 'storefront_id' => 'store_settle'], $meta)),
        'created_at'   => $updatedAt,
        'updated_at'   => $updatedAt,
        'deleted_at'   => $deletedAt,
    ]);
    settlementCoverageDb()->table('checkouts')->insert(['uuid' => $id . '_checkout_uuid', 'public_id' => 'checkout_' . $id, 'order_uuid' => $id . '_uuid', 'gateway_uuid' => 'settle_gateway_uuid']);
}

function balanceSweepRun(array $options = []): array
{
    $buffer  = new BufferedOutput();
    $command = new CheckBalancePayments();
    $command->setInput(new ArrayInput($options, $command->getDefinition()));
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    return [$command->handle(), $buffer->fetch()];
}

function balanceSweepBalance(string $id): array
{
    return [
        'balance_due' => settlementCoverageStoredMeta($id . '_uuid')['balance_due'] ?? null,
        'adjustments' => count(settlementCoverageStoredMeta($id . '_uuid')['payment_adjustments'] ?? []),
    ];
}

beforeEach(function () {
    settlementCoverageSchema();
    settlementCoverageGateway('stripe', ['secret_key' => 'sk_test_balance_sweep']);

    $due = fn (string $link) => ['balance_due' => 700, 'balance_payment' => ['gateway' => 'stripe', 'reference' => $link, 'amount' => 700]];
    $now = now()->subHour()->toDateTimeString();
    balanceSweepOrder('order_paid', $due('plink_paid'), $now);
    balanceSweepOrder('order_waiting', $due('plink_waiting'), $now);
    balanceSweepOrder('order_outage', $due('plink_outage'), $now);
    balanceSweepOrder('order_old', $due('plink_old'), now()->subDays(30)->toDateTimeString());
    balanceSweepOrder('order_deleted', $due('plink_deleted'), $now, $now);
    balanceSweepOrder('order_settled', ['balance_due' => 0, 'balance_payment' => ['reference' => 'plink_settled']], $now);
    balanceSweepOrder('order_unlinked', ['balance_due' => 700], $now);
});

afterEach(function () {
    settlementCoverageRestoreStripe();
});

test('the sweep settles recently changed balances paid through their link and goes on past a failing one', function () {
    $stripe = balanceSweepStripe(['plink_paid', 'plink_old', 'plink_deleted', 'plink_settled'], ['plink_outage']);

    [$exitCode, $output] = balanceSweepRun();

    // Only open balances with a link, changed in the last 14 days, are asked about.
    expect($exitCode)->toBe(0)
        ->and($stripe->asked)->toBe(['plink_paid', 'plink_waiting', 'plink_outage'])
        ->and($output)->toContain('Settled 1 paid balance(s).')
        ->and(balanceSweepBalance('order_paid'))->toBe(['balance_due' => 0, 'adjustments' => 1])
        ->and(balanceSweepBalance('order_waiting'))->toBe(['balance_due' => 700, 'adjustments' => 0])
        ->and(balanceSweepBalance('order_outage'))->toBe(['balance_due' => 700, 'adjustments' => 0])
        ->and(balanceSweepBalance('order_old'))->toBe(['balance_due' => 700, 'adjustments' => 0])
        ->and(session('company'))->toBe('company_order_outage');
});

test('the sweep looks further back when asked to', function () {
    $stripe = balanceSweepStripe(['plink_old']);

    [$exitCode, $output] = balanceSweepRun(['--days' => '45']);

    expect($exitCode)->toBe(0)
        ->and($stripe->asked)->toBe(['plink_paid', 'plink_waiting', 'plink_outage', 'plink_old'])
        ->and($output)->toContain('Settled 1 paid balance(s).')
        ->and(balanceSweepBalance('order_old'))->toBe(['balance_due' => 0, 'adjustments' => 1])
        ->and(balanceSweepBalance('order_paid'))->toBe(['balance_due' => 700, 'adjustments' => 0]);
});

test('the sweep always looks back at least a day', function () {
    $stripe = balanceSweepStripe([]);

    [, $output] = balanceSweepRun(['--days' => '0']);

    expect($stripe->asked)->toBe(['plink_paid', 'plink_waiting', 'plink_outage'])
        ->and($output)->toContain('Settled 0 paid balance(s).');
});
