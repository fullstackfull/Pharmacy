<?php

namespace App\Services;

use App\Traits\FileManagerTrait;

class BannerService
{
    use FileManagerTrait;

    public function getProcessedData(object $request, ?string $bannerUrl = null, ?string $image = null, ?string $mobileImage = null): array
    {
        if ($image) {
            $imageName = $request->file('image') ? $this->update(dir:'banner/', oldImage:$image, format: 'webp', image: $request->file('image')) : $image;
        }else {
            $imageName = $this->upload(dir:'banner/', format: 'webp', image: $request->file('image'));
        }

        return [
            'banner_type' => $request['banner_type'],
            'mobile_photo' => $this->getProcessedMobileImage(request: $request, mobileImage: $mobileImage),
            'resource_type' => $request['resource_type'],
            'resource_id' => $request[$request->resource_type . '_id'],
            'theme' => theme_root_path(),
            'title' => $request['title'],
            'sub_title' => $request['sub_title'],
            'button_text' => $request['button_text'],
            'background_color' => $request['background_color'],
            'url' => $bannerUrl,
            'photo' => $imageName,
        ];
    }

    /**
     * The optional phone-shaped image. Left alone when the form sends no new
     * file, so editing a banner never silently drops the one already stored.
     */
    private function getProcessedMobileImage(object $request, ?string $mobileImage = null): ?string
    {
        if (!$request->file('mobile_image')) {
            return $mobileImage;
        }

        if ($mobileImage) {
            return $this->update(dir: 'banner/', oldImage: $mobileImage, format: 'webp', image: $request->file('mobile_image'));
        }

        return $this->upload(dir: 'banner/', format: 'webp', image: $request->file('mobile_image'));
    }

    public function getBannerTypes(): array
    {
        $bannerTypes = [];
        if (theme_root_path() == 'default') {
            $bannerTypes = [
                "Main Banner" => translate('main_Banner'),
                "Popup Banner" => translate('popup_Banner'),
                "Footer Banner" => translate('footer_Banner'),
                "Main Section Banner" => translate('main_Section_Banner'),
                // Placed on the category page named by its category resource, and
                // inherited by that category's sub-categories.
                "Category Banner" => translate('category_Banner'),
                // Placed above its category's product row on the home page; the apps
                // render it inside that same row using the banner's mobile image.
                "Category Section Banner" => translate('category_Section_Banner'),
            ];

        }elseif (theme_root_path() == 'theme_aster') {
            $bannerTypes = [
                "Main Banner" => translate('main_Banner'),
                "Popup Banner" => translate('popup_Banner'),
                "Footer Banner" => translate('footer_Banner'),
                "Main Section Banner" => translate('main_Section_Banner'),
                "Header Banner" => translate('header_Banner'),
                "Sidebar Banner" => translate('sidebar_Banner'),
                "Top Side Banner" => translate('top_Side_Banner'),
            ];
        }elseif (theme_root_path() == 'theme_fashion') {
            $bannerTypes = [
                "Main Banner" => translate('main_Banner'),
                "Popup Banner" => translate('popup_Banner'),
                "Promo Banner Left" => translate('promo_banner_left'),
                "Promo Banner Middle Top" => translate('promo_banner_middle_top'),
                "Promo Banner Middle Bottom" => translate('promo_banner_middle_bottom'),
                "Promo Banner Right" => translate('promo_banner_right'),
                "Promo Banner Bottom" => translate('promo_banner_bottom'),
            ];
        }

        return $bannerTypes;
    }

}
