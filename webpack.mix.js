const mix = require('laravel-mix');

/*
 |--------------------------------------------------------------------------
 | Kohl asset build
 |--------------------------------------------------------------------------
 |
 | This file previously compiled resources/js/app.js and resources/sass/app.scss,
 | neither of which exists — resources/sass/ is absent and resources/js/ held a
 | stray image. Every stylesheet in public/assets was therefore hand-edited, which
 | is why the codebase has 175 CSS files, three parallel admin asset trees and no
 | token layer.
 |
 | Output goes to public/assets/kohl/ only. Nothing here touches the legacy
 | back-end / backend / new trees, so a build can never break an existing page:
 | the Kohl bundles are loaded last and additively.
 |
 */

mix.setPublicPath('public');

mix.sass('resources/css/kohl/console.scss', 'assets/kohl/css')
   .sass('resources/css/kohl/store.scss', 'assets/kohl/css')
   // Monitoring ships as its own bundle rather than inside the console one: it is a large,
   // single-area stylesheet, and every other admin page would otherwise pay to download it.
   .sass('resources/css/kohl/monitoring.scss', 'assets/kohl/css')
   .sass('resources/css/kohl/developer.scss', 'assets/kohl/css')
   .sass('resources/css/kohl/analytics.scss', 'assets/kohl/css')
   .js('resources/js/kohl/index.js', 'assets/kohl/js/kohl.js')
   .js('resources/js/kohl/monitoring.js', 'assets/kohl/js')
   .options({
       processCssUrls: false,   // asset URLs are resolved by the app's dynamicAsset() helper
   });

if (mix.inProduction()) {
    mix.version();
}
