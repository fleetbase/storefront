<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Push\ApnClientFactory;
use Fleetbase\Storefront\Push\PushMessage;
use Fleetbase\Storefront\Push\Transports\ApnTransport;
use Fleetbase\Storefront\Push\Transports\FcmTransport;
use Illuminate\Http\Request;

class NotificationChannelController extends StorefrontController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'notification_channel';

    /**
     * Send a test push to a single device token through a notification channel and report
     * the provider's raw outcome, so credential or token problems can be diagnosed.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testPush(string $id, Request $request, FcmTransport $fcm, ApnTransport $apn)
    {
        $token = trim((string) $request->input('token'));
        if ($token === '') {
            return response()->error('A device token is required.');
        }

        $channel = NotificationChannel::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('app_key', $id);
        })->where('company_uuid', session('company'))->first();

        if (!$channel) {
            return response()->error('Notification channel not found.', 404);
        }

        $message = PushMessage::create(
            $request->input('title', 'Test notification'),
            $request->input('body', 'Push notifications from ' . ($channel->name ?? 'your storefront') . ' are working.'),
            ['type' => 'test']
        );

        $results = [];
        try {
            if ($channel->scheme === 'fcm') {
                $outcome   = $fcm->send($channel, $message, [$token])[$token] ?? null;
                $results[] = ['environment' => null, 'status' => $outcome?->status ?? 'error', 'reason' => $outcome?->reason];
            } else {
                $environments = $request->filled('environment') ? [$request->input('environment')] : ApnClientFactory::environments($channel);
                foreach ($environments as $environment) {
                    $outcome   = $apn->send($channel, $environment, $message, [$token])[$token] ?? null;
                    $results[] = ['environment' => $environment, 'status' => $outcome?->status ?? 'error', 'reason' => $outcome?->reason];
                    if ($outcome?->status === 'sent') {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $results[] = ['environment' => null, 'status' => 'error', 'reason' => $e->getMessage()];
        }

        $sent = collect($results)->contains('status', 'sent');

        return response()->json([
            'status'  => $sent ? 'OK' : 'FAILED',
            'scheme'  => $channel->scheme,
            'results' => $results,
        ]);
    }
}
