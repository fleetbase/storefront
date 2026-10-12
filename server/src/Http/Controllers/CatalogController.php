<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Storefront\Http\Resources\Catalog as CatalogResource;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogSubject;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CatalogController extends StorefrontController
{
    public $resource = 'catalog';

    /**
     * A catalog is read with everything the editor shows: categories and their products,
     * hours, and the stores and trucks that serve it.
     */
    public function onQueryRecord(Builder $builder)
    {
        $builder->with(['categories.products', 'hours', 'assignments.subject']);
    }

    /**
     * Replace the stores and trucks that serve a catalog.
     *
     * @return \Illuminate\Http\Response
     */
    public function assignSubjects(string $id, Request $request)
    {
        $catalog = Catalog::where('company_uuid', session('company'))->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))->first();

        if (!$catalog) {
            return response()->error('Catalog not found.', 404);
        }

        $this->syncSubjects($catalog, Store::class, $request->array('stores'));
        $this->syncSubjects($catalog, FoodTruck::class, $request->array('food_trucks'));

        $catalog->load(['categories.products', 'hours', 'assignments.subject']);

        return response()->json(['catalog' => new CatalogResource($catalog)]);
    }

    /**
     * Sync one subject type's pivot rows to the given uuids, matching how FoodTruck::setCatalogs writes them.
     */
    private function syncSubjects(Catalog $catalog, string $subjectType, array $subjectIds = []): void
    {
        $incoming = collect($subjectIds)
            ->map(fn ($item) => is_string($item) ? $item : data_get($item, 'uuid', data_get($item, 'id')))
            ->filter(fn ($uuid) => is_string($uuid) && Str::isUuid($uuid))
            ->unique()
            ->values();

        CatalogSubject::where(['catalog_uuid' => $catalog->uuid, 'subject_type' => $subjectType])
            ->whereNotIn('subject_uuid', $incoming)
            ->delete();

        foreach ($incoming as $subjectUuid) {
            CatalogSubject::firstOrCreate(
                [
                    'catalog_uuid' => $catalog->uuid,
                    'subject_uuid' => $subjectUuid,
                    'subject_type' => $subjectType,
                ],
                [
                    'company_uuid'    => $catalog->company_uuid,
                    'created_by_uuid' => session('user'),
                ]
            );
        }
    }
}
