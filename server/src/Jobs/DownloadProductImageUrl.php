<?php

namespace Fleetbase\Storefront\Jobs;

use Fleetbase\Storefront\Models\Product;
use Fleetbase\Models\File;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DownloadProductImageUrl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The uuid of the product.
     *
     * @var string
     */
    public $product;

    /**
     * The url of the image to download.
     *
     * @var string
     */
    public $url;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Product $product, string $url)
    {
        $this->product = $product->uuid;
        $this->url = $url;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // get product record
        $product = Product::find($this->product);
        // download and save to product as image
        $image = Utils::urlToStorefrontFile($this->url, 'storefront_product', $product);

        // if image is \Fleetbase\Models\File then set as primary image
        if ($image instanceof File) {
            $product->update(['primary_image_uuid' => $image->uuid]);
        }
    }
}
