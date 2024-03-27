<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\Storefront\Models\AddonCategory;
use Fleetbase\Support\Http;
use Illuminate\Http\Request;

class AddonCategoryController extends StorefrontController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'addon_category';

    public function createRecord(Request $request)
    {
        try {
            $this->validateRequest($request);

            $record = $this->model->createRecordFromRequest($request, null, function (&$request, AddonCategory &$addonCategory) {
                $addons = $request->array('addonCategory.addons');
                $addonCategory->setAddons($addons);
            });

            if (Http::isInternalRequest($request)) {
                $this->resource::wrap($this->resourceSingularlName);
                return new $this->resource($record);
            }

            return new $this->resource($record);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->error($e->getMessage());
        } catch (\Fleetbase\Exceptions\FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        }
    }

    public function updateRecord(Request $request, string $id)
    {
        try {
            $this->validateRequest($request);
            $record = $this->model->updateRecordFromRequest($request, $id, function (&$request, AddonCategory &$addonCategory) {
                $addons = $request->array('addonCategory.addons');
                $addonCategory->setAddons($addons);
            });

            if (Http::isInternalRequest($request)) {
                $this->resource::wrap($this->resourceSingularlName);

                return new $this->resource($record);
            }

            return new $this->resource($record);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->error($e->getMessage());
        } catch (\Fleetbase\Exceptions\FleetbaseRequestValidationException $e) {
            return response()->error($e->getErrors());
        }
    }
}
