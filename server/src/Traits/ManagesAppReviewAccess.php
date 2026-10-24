<?php

namespace Fleetbase\Storefront\Traits;

use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\AppReview;
use Illuminate\Http\Request;

/**
 * The Console's App Review access endpoints for a store or network:
 *
 *   GET {id}/app-review  the status (never the code)
 *   PUT {id}/app-review  {enabled?: bool, regenerate_code?: bool}; the response carries the
 *                        plain `code` only when this request generated one
 */
trait ManagesAppReviewAccess
{
    /**
     * The company's store or network with this id or public id.
     */
    abstract protected function appReviewOwner(string $id, Request $request): Store|Network|null;

    public function appReview(string $id, Request $request)
    {
        $owner = $this->appReviewOwner($id, $request);
        if (!$owner) {
            return response()->error('Storefront not found.', 404);
        }

        return response()->json(['app_review' => AppReview::status(AppReview::accessFor($owner))]);
    }

    public function manageAppReview(string $id, Request $request)
    {
        $owner = $this->appReviewOwner($id, $request);
        if (!$owner) {
            return response()->error('Storefront not found.', 404);
        }

        $request->validate([
            'enabled'         => 'sometimes|boolean',
            'regenerate_code' => 'sometimes|boolean',
        ]);

        $enabled = $request->has('enabled') ? $request->boolean('enabled') : null;

        [$access, $code] = AppReview::update($owner, $enabled, $request->boolean('regenerate_code'), $request->session()->get('user'));

        $status = AppReview::status($access);
        if ($code !== null) {
            $status['code'] = $code;
        }

        return response()->json(['app_review' => $status]);
    }
}
