<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Storefront\Http\Requests\CustomerSegmentRequest;
use Fleetbase\Storefront\Models\CustomerSegment;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Promotions\SegmentResolver;
use Illuminate\Http\Request;

class CustomerSegmentController extends StorefrontController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'customer_segment';

    /**
     * Validates creates and updates.
     *
     * @var string
     */
    public $request = CustomerSegmentRequest::class;

    /**
     * Count and sample the customers matching a saved segment.
     */
    public function preview(string $id, SegmentResolver $segments)
    {
        $segment = CustomerSegment::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))
            ->first();
        $owner = $segment ? $this->owner($segment->owner_uuid) : null;

        if (!$owner) {
            return response()->error('Segment not found.', 404);
        }

        return response()->json($this->summary($segments, $owner, (array) ($segment->rules ?? [])));
    }

    /**
     * Count and sample the customers matching unsaved rules, for the segment builder.
     *
     * Body: `owner_uuid` and `rules`.
     */
    public function previewRules(Request $request, SegmentResolver $segments)
    {
        $owner = $this->owner((string) $request->input('owner_uuid'));
        if (!$owner) {
            return response()->error('The owner must be one of your stores or networks.');
        }

        return response()->json($this->summary($segments, $owner, (array) $request->input('rules', [])));
    }

    protected function owner(string $uuid): Store|Network|null
    {
        return Store::where('uuid', $uuid)->where('company_uuid', session('company'))->first()
            ?? Network::where('uuid', $uuid)->where('company_uuid', session('company'))->first();
    }

    protected function summary(SegmentResolver $segments, Store|Network $owner, array $rules): array
    {
        $query = $segments->query($owner, array_intersect_key($rules, array_flip(SegmentResolver::RULES)));

        return [
            'count'  => (clone $query)->count(),
            'sample' => $query->limit(10)->get(['uuid', 'public_id', 'name', 'email', 'phone'])->map(fn ($customer) => [
                'id'    => $customer->public_id,
                'name'  => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ])->all(),
        ];
    }
}
