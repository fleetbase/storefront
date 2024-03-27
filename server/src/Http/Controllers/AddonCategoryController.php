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

    /**
     * Creates a new record based on the provided request data.
     *
     * This method handles the creation of a new record using data from the request.
     * It includes a validation step, creation of the record, and an optional closure
     * to perform additional operations on the model and request data. The method
     * returns an instance of a resource, which wraps the created record. Error handling
     * is implemented to catch and respond to various exceptions that might occur during the process.
     *
     * @param Request $request the incoming HTTP request containing data for the new record
     *
     * @return mixed an instance of the resource class containing the created record, or an error response
     *
     * @throws \Exception                                                general exceptions with a message
     * @throws \Illuminate\Database\QueryException                       database query exceptions with a message
     * @throws \Fleetbase\Exceptions\FleetbaseRequestValidationException custom validation exceptions with error details
     */
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

    /**
     * Updates an existing record based on the provided request data.
     *
     * This method updates a record identified by the provided ID using data from the request.
     * It includes a validation step, updating of the record, and an optional closure
     * to perform additional operations on the model and request data. The method returns
     * an instance of a resource, which wraps the updated record. Error handling is implemented
     * to catch and respond to various exceptions that might occur during the update process.
     *
     * @param Request $request the incoming HTTP request containing data for updating the record
     * @param string  $id      the identifier of the record to be updated
     *
     * @return mixed an instance of the resource class containing the updated record, or an error response
     *
     * @throws \Exception                                                general exceptions with a message
     * @throws \Illuminate\Database\QueryException                       database query exceptions with a message
     * @throws \Fleetbase\Exceptions\FleetbaseRequestValidationException custom validation exceptions with error details
     */
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
