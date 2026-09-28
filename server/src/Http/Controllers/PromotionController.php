<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Storefront\Http\Requests\PromotionRequest;
use Fleetbase\Storefront\Http\Resources\PromotionCode as PromotionCodeResource;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\PromotionCode;
use Illuminate\Http\Request;

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

    protected function generateCode(int $length, string $prefix): string
    {
        return PromotionCode::generate($length, $prefix);
    }
}
