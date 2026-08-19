<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Traits\CacheManagerTrait;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    use CacheManagerTrait;

    /** Banner types that belong to a specific screen and are served by that screen's own endpoint. */
    private const SCREEN_SCOPED_TYPES = ['Category Banner'];

    public function getBannerList(Request $request): JsonResponse
    {
        // Screen-scoped banners are excluded so the installed apps keep receiving
        // exactly the home-screen banners they render today; the category screen
        // reads its own from /categories/page-header/{id}.
        $banners = $this->cacheBannerTable()->whereNotIn('banner_type', self::SCREEN_SCOPED_TYPES);
        $productIds = [];
        $shopIds = [];
        $brandIds = [];
        $categoryIds = [];
        $bannerData = [];
        foreach ($banners as $banner) {
            if ($banner['resource_type'] == 'product' && !in_array($banner['resource_id'], $productIds)) {
                $productIds[] = $banner['resource_id'];
                $product = Product::find($banner['resource_id']);
                $banner['product'] = Helpers::product_data_formatting($product);
            }
            if ($banner['resource_type'] == 'shop' && !in_array($banner['resource_id'], $shopIds)) {
                $shopIds[] = $banner['resource_id'];
                $banner['shop'] = Shop::where('id', $banner['resource_id'])->first();
            }
            if ($banner['resource_type'] == 'brand' && !in_array($banner['resource_id'], $brandIds)) {
                $brandIds[] = $banner['resource_id'];
                $banner['brand'] = Brand::where('id', $banner['resource_id'])->first();
            }
            if ($banner['resource_type'] == 'category' && !in_array($banner['resource_id'], $categoryIds)) {
                $categoryIds[] = $banner['resource_id'];
                $banner['category'] = Category::where('id', $banner['resource_id'])->first();
            }
            $bannerData[] = $banner;
        }

        return response()->json($bannerData, 200);

    }
}
