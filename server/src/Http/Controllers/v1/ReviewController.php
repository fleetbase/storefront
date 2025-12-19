<?php

namespace Fleetbase\Storefront\Http\Controllers\v1;

use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\File;
use Fleetbase\Storefront\Http\Requests\CreateReviewRequest;
use Fleetbase\Storefront\Http\Resources\Review as StorefrontReview;
use Fleetbase\Storefront\Models\Review;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReviewController extends Controller
{
    /**
     * Query for Storefront Review resources.
     *
     * @return \Illuminate\Http\Response
     */
    public function query(Request $request)
    {
        $limit  = $request->input('limit', false);
        $offset = $request->input('offset', false);
        $sort   = $request->input('sort');

        if ($sort) {
            $this->applySort($request, $sort);
        }

        if (session('storefront_store')) {
            $results = Review::queryWithRequestCached($request, function (&$query) use ($limit, $offset) {
                $query->where('subject_uuid', session('storefront_store'));

                if ($limit) {
                    $query->limit($limit);
                }

                if ($offset) {
                    $query->offset($offset);
                }
            });
        }

        if (session('storefront_network')) {
            if ($request->filled('store')) {
                $store = Store::where([
                    'company_uuid' => session('company'),
                    'public_id'    => $request->input('store'),
                ])->whereHas('networks', function ($q) {
                    $q->where('network_uuid', session('storefront_network'));
                })->first();

                if (!$store) {
                    return response()->json(['error' => 'Cannot find reviews for store'], 400);
                }

                $results = Review::queryWithRequestCached($request, function (&$query) use ($store, $limit, $offset) {
                    $query->where('subject_uuid', $store->uuid);

                    if ($limit) {
                        $query->limit($limit);
                    }

                    if ($offset) {
                        $query->limit($offset);
                    }
                });
            }
        }

        return StorefrontReview::collection($results);
    }

    public function applySort($request, $sort)
    {
        if ($sort) {
            switch ($sort) {
                case 'highest':
                case 'highest rated':
                    $request->merge(['sort' => 'rating', 'sort_direction' => 'desc']);

                    break;

                case 'lowest':
                case 'lowest rated':
                    $request->merge(['sort' => 'rating', 'sort_direction' => 'asc']);

                    break;

                case 'newest':
                case 'newest first':
                    $request->merge(['sort' => 'created_at', 'sort_direction' => 'desc']);

                    break;

                case 'oldest':
                case 'oldest first':
                    $request->merge(['sort' => 'created_at', 'sort_direction' => 'asc']);

                    break;

                default:
                    // Handle unknown sorting criteria
                    break;
            }
        }
    }

    /**
     * Coutns the number of ratings between 1-5 for a store.
     *
     * @return \Illuminate\Http\Response
     */
    public function count(Request $request)
    {
        $counts = [];
        $range  = range(1, 5);

        if (session('storefront_store')) {
            foreach ($range as $rating) {
                $counts[$rating] = Review::where(['subject_uuid' => session('storefront_store'), 'rating' => $rating])->count();
            }
        }

        if (session('storefront_network')) {
            if ($request->filled('store')) {
                $store = Store::where([
                    'company_uuid' => session('company'),
                    'public_id'    => $request->input('store'),
                ])->whereHas('networks', function ($q) {
                    $q->where('network_uuid', session('storefront_network'));
                })->first();

                if (!$store) {
                    return response()->json(['error' => 'Cannot count reviews for store'], 400);
                }

                foreach ($range as $rating) {
                    $counts[$rating] = Review::where(['subject_uuid' => $store->uuid, 'rating' => $rating])->count();
                }
            }
        }

        return response()->json($counts);
    }

    /**
     * Finds a single Storefront Review resources.
     *
     * @param string $id
     *
     * @return \Fleetbase\Http\Response
     */
    public function find($id)
    {
        // find for the review
        try {
            $review = Review::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->error('Review resource not found.');
        }

        // response the review resource
        return new StorefrontReview($review);
    }

    /**
     * Create a review.
     *
     * @return \Fleetbase\Http\Response
     */
    public function create(CreateReviewRequest $request)
    {
        $customer    = Storefront::getCustomerFromToken();
        $about       = Storefront::about();
        $disk        = $request->input('disk', config('filesystems.default'));
        $bucket      = $request->input('bucket', config('filesystems.disks.' . $disk . '.bucket', config('filesystems.disks.s3.bucket')));

        if (!$customer) {
            return response()->error('Not authorized to create reviews');
        }

        $subject = Utils::resolveSubject($request->input('subject'));

        if (!$subject) {
            return response()->error('Invalid subject for review');
        }

        $review = Review::create([
            'created_by_uuid' => $customer->user_uuid,
            'customer_uuid'   => $customer->uuid,
            'subject_uuid'    => $subject->uuid,
            'subject_type'    => Utils::getMutationType($subject),
            'rating'          => $request->input('rating'),
            'content'         => $request->input('content'),
        ]);

        // if files provided
        if ($request->filled('files')) {
            $files = $request->input('files');

            foreach ($files as $upload) {
                $data       = Utils::get($upload, 'data');
                $mimeType   = Utils::get($upload, 'type');
                $extension  = File::getExtensionFromMimeType($mimeType);
                $bucketPath = 'hyperstore/' . $about->public_id . '/review-photos/' . $review->uuid . '/' . File::randomFileName($extension);

                // upload file to path
                $upload = Storage::disk($disk)->put($bucketPath, base64_decode($data), 'public');

                // create the file
                $file = File::create([
                    'company_uuid'      => session('company'),
                    'uploader_uuid'     => $customer->user_uuid,
                    'subject_uuid'      => $review->uuid,
                    'subject_type'      => Utils::getMutationType($review),
                    'name'              => basename($bucketPath),
                    'original_filename' => basename($bucketPath),
                    'extension'         => $extension,
                    'content_type'      => $mimeType,
                    'path'              => $bucketPath,
                    'bucket'            => $bucket,
                    'type'              => 'storefront_review_upload',
                    'size'              => Utils::getBase64ImageSize($data),
                ]);

                $review->files->push($file);
            }
        }

        return new StorefrontReview($review);
    }

    /**
     * Deletes a Storefront Review resources.
     *
     * @return \Fleetbase\Http\Resources\v1\DeletedResource
     */
    public function delete($id)
    {
        // find for the product
        try {
            $review = Review::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->error('Review resource not found.');
        }

        // delete the review
        $review->delete();

        // response the review resource
        return new DeletedResource($review);
    }
}
