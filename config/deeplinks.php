<?php

/*
| Which of this shop's URLs open the mobile app instead of the browser.
|
| These lists are not documentation: `ios_paths` is written verbatim into
| public/.well-known/apple-app-site-association, which is the file iOS downloads to decide whether
| a tap opens the app. A path that is not here opens Safari, whatever the app declares.
|
| Android verifies the whole host rather than a path list, so `android_paths` is not written into
| assetlinks.json — it is the list the app's intent filters have to mirror. It is published on the
| deep-link setup screen and through the API so the app team reads it from the shop rather than
| from a message.
|
| `enabled_routes` names the same destinations by route name. It is what the API hands the app when
| it resolves a link, so the app can route natively — open the product screen — instead of dropping
| the customer into a web view.
|
| A trailing * matches any suffix, which is the association file's own syntax.
*/

return [
    /*
    | route name => the destination the app should open, and how to read its parameter.
    */
    'enabled_routes' => [
        'home' => ['path' => '/', 'target' => 'home', 'parameter' => null],
        'product' => ['path' => '/product/*', 'target' => 'product', 'parameter' => 'slug'],
        'brand-products' => ['path' => '/brand/*', 'target' => 'brand', 'parameter' => 'slug'],
        'products' => ['path' => '/products', 'target' => 'product_list', 'parameter' => null],
        'track-order.index' => ['path' => '/track-order/*', 'target' => 'order_tracking', 'parameter' => 'order_id'],
        // The campaign short link. It resolves server-side to one of the targets above, carrying
        // the campaign's attribution with it, so a poster's QR code opens the app on the right
        // screen and the visit is still counted against the campaign that caused it.
        'campaign.go' => ['path' => '/go/*', 'target' => 'campaign', 'parameter' => 'code'],
    ],

    'android_paths' => [
        '/',
        '/product/*',
        '/brand/*',
        '/products',
        '/track-order/*',
        '/go/*',
    ],

    'ios_paths' => [
        '/',
        '/product/*',
        '/brand/*',
        '/products',
        '/track-order/*',
        '/go/*',
    ],
];
