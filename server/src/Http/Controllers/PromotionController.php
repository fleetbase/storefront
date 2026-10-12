<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Storefront\Http\Requests\PromotionRequest;
use Fleetbase\Storefront\Http\Resources\Campaign as CampaignResource;
use Fleetbase\Storefront\Http\Resources\PromotionCode as PromotionCodeResource;
use Fleetbase\Storefront\Models\Campaign;
use Fleetbase\Storefront\Models\CustomerSegment;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\PromotionRedemption;
use Fleetbase\Storefront\Models\PromotionCode;
use Fleetbase\Storefront\Promotions\CampaignDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PromotionController extends StorefrontController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'promotion';

    /**
     * Validates creates and updates.
     *
     * @var string
     */
    public $request = PromotionRequest::class;

    /**
     * Generate a batch of unique codes for a promotion.
     *
     * Body: `count` (1-1000, default 1), `length` (6-16, default 8), `prefix`, `usage_limit`
     * (per code, e.g. 1 for single-use codes), `expires_at`, and `code` to create one specific code.
     */
    public function generateCodes(string $id, Request $request)
    {
        $promotion = Promotion::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))
            ->first();

        if (!$promotion) {
            return response()->error('Promotion not found.', 404);
        }

        $count  = min(max((int) $request->input('count', 1), 1), 1000);
        $length = min(max((int) $request->input('length', 8), 6), 16);
        $prefix = PromotionCode::normalize($request->input('prefix', ''));
        $exact  = $request->filled('code') ? PromotionCode::normalize($request->input('code')) : null;

        if ($exact !== null && PromotionCode::where('company_uuid', $promotion->company_uuid)->where('code', $exact)->exists()) {
            return response()->error('The code "' . $exact . '" is already in use.');
        }

        $codes    = [];
        $attempts = 0;
        while (count($codes) < ($exact ? 1 : $count) && $attempts++ < $count * 10) {
            $code = $exact ?? $this->generateCode($length, $prefix);
            if (isset($codes[$code]) || PromotionCode::where('company_uuid', $promotion->company_uuid)->where('code', $code)->exists()) {
                continue;
            }

            $codes[$code] = PromotionCode::create([
                'company_uuid'   => $promotion->company_uuid,
                'promotion_uuid' => $promotion->uuid,
                'code'           => $code,
                'status'         => PromotionCode::STATUS_ACTIVE,
                'usage_limit'    => $request->input('usage_limit'),
                'expires_at'     => $request->input('expires_at'),
            ]);
        }

        if ($promotion->trigger !== Promotion::TRIGGER_CODE) {
            $promotion->update(['trigger' => Promotion::TRIGGER_CODE]);
        }

        return response()->json(['codes' => PromotionCodeResource::collection(array_values($codes))]);
    }

    /**
     * Announce a promotion to customers with a campaign.
     *
     * Body (all optional): `title`, `body`, `segment` (segment uuid), `channels`, `send_at`
     * (defaults to when the promotion starts, or now). Returns the campaign; it is sent right
     * away when its send time has arrived, otherwise by the campaign scheduler.
     */
    public function announce(string $id, Request $request, CampaignDispatcher $dispatcher)
    {
        $promotion = Promotion::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))
            ->first();

        if (!$promotion) {
            return response()->error('Promotion not found.', 404);
        }

        if (in_array($promotion->status, [Promotion::STATUS_PAUSED, Promotion::STATUS_ENDED], true)) {
            return response()->error('Only draft or active promotions can be announced.');
        }

        $segment = $request->filled('segment')
            ? CustomerSegment::where('company_uuid', session('company'))->where('uuid', $request->input('segment'))->first()
            : null;
        if ($request->filled('segment') && !$segment) {
            return response()->error('Segment not found.', 404);
        }

        $sendAt   = $request->filled('send_at') ? Carbon::parse($request->input('send_at')) : ($promotion->starts_at && $promotion->starts_at->isFuture() ? $promotion->starts_at : now());
        $campaign = Campaign::create([
            'company_uuid'   => $promotion->company_uuid,
            'created_by_uuid'=> session('user'),
            'owner_uuid'     => $promotion->owner_uuid,
            'owner_type'     => $promotion->owner_type,
            'segment_uuid'   => $segment?->uuid,
            'promotion_uuid' => $promotion->uuid,
            'name'           => 'Announce: ' . $promotion->name,
            'status'         => Campaign::STATUS_SCHEDULED,
            'channels'       => $request->input('channels', [Campaign::CHANNEL_PUSH, Campaign::CHANNEL_INBOX]),
            'title'          => $request->input('title', $promotion->name),
            'body'           => $request->input('body', $promotion->description ?: 'A new deal is available, tap to see it.'),
            'image_uuid'     => $promotion->image_uuid,
            'action'         => ['type' => 'promotion', 'id' => $promotion->public_id],
            'send_at'        => $sendAt,
        ]);

        if (!$campaign->send_at->isFuture()) {
            $dispatcher->dispatch($campaign);
        }

        return new CampaignResource($campaign->refresh());
    }

    protected function generateCode(int $length, string $prefix): string
    {
        return PromotionCode::generate($length, $prefix);
    }

    /**
     * Counts for the promotions hub: promotions by status, campaigns, segments and redemptions
     * for the store or network in `owner`.
     *
     * @return \Illuminate\Http\Response
     */
    public function hub(Request $request)
    {
        $company = session('company');
        $owner   = $request->input('owner');

        $promotions = Promotion::where('company_uuid', $company);
        $campaigns  = \Fleetbase\Storefront\Models\Campaign::where('company_uuid', $company);
        $segments   = \Fleetbase\Storefront\Models\CustomerSegment::where('company_uuid', $company);

        if ($owner) {
            $promotions->where('owner_uuid', $owner);
            $campaigns->where('owner_uuid', $owner);
            $segments->where('owner_uuid', $owner);
        }

        $promotionIds = (clone $promotions)->pluck('uuid');
        $redeemed     = PromotionRedemption::where('company_uuid', $company)->whereIn('promotion_uuid', $promotionIds)->where('status', PromotionRedemption::STATUS_REDEEMED);
        $byStatus     = (clone $promotions)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $now          = now();
        $scheduled    = (clone $promotions)->where('status', 'active')->where('starts_at', '>', $now)->count();

        return response()->json([
            'promotions' => [
                'total'     => (clone $promotions)->count(),
                'active'    => max(0, (int) ($byStatus['active'] ?? 0) - $scheduled),
                'scheduled' => $scheduled,
                'paused'    => (int) ($byStatus['paused'] ?? 0),
                'ended'     => (int) ($byStatus['ended'] ?? 0),
                'draft'     => (int) ($byStatus['draft'] ?? 0),
            ],
            'campaigns'   => $campaigns->count(),
            'segments'    => $segments->count(),
            'redemptions' => [
                'total'  => (clone $redeemed)->count(),
                'amount' => (int) (clone $redeemed)->sum('amount'),
            ],
        ]);
    }
}
