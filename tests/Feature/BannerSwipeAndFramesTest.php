<?php

namespace Tests\Feature;

use App\Services\Theme\SectionRegistry;
use Tests\TestCase;

/**
 * Swipe belongs to every banner section, and extra frames belong to every image-carrying block.
 *
 * Both started life inside the mosaic only, which is how the merchant ended up with a tile that
 * plainly held three pictures in the builder and showed one everywhere else. These pin the two
 * halves of the fix: the options are offered, and the shared partial actually draws them.
 */
class BannerSwipeAndFramesTest extends TestCase
{
    /** The blocks a merchant can hang more than one picture on. */
    private const FRAMED_BLOCKS = ['banner', 'slide', 'mosaic_tile'];

    public function test_extra_frames_are_declared_by_every_image_carrying_block(): void
    {
        // A key the schema does not declare is invisible to the builder AND stripped on save —
        // image_2 lived in the database for exactly that reason and never reached the page.
        $registry = app(SectionRegistry::class);

        foreach (self::FRAMED_BLOCKS as $type) {
            $normalized = $registry->normalizeBlockSettings($type, [
                'image' => 'one.png', 'image_2' => 'two.png', 'image_3' => 'three.png',
            ]);

            $this->assertSame('two.png', $normalized['image_2'] ?? null, "{$type} loses its second frame");
            $this->assertSame('three.png', $normalized['image_3'] ?? null, "{$type} loses its third frame");
        }
    }

    public function test_swipe_is_offered_by_every_banner_section(): void
    {
        $types = app(SectionRegistry::class)->types();

        $this->assertContains('swipe', $types['promotional_banner']['schema']['style']['options']);
        $this->assertContains('swipe', $types['store_banner']['schema']['layout']['options']);
        $this->assertContains('swipe', $types['banner_mosaic']['schema']['display']['options']);
    }

    public function test_the_shared_banner_partial_draws_a_swipe_row_of_crossfading_tiles(): void
    {
        $markup = $this->renderGrid(
            cards: [
                ['image' => 'one.png', 'images' => ['one.png', 'two.png'], 'span' => 'square', 'title' => 'Pair'],
                ['image' => 'solo.png', 'images' => ['solo.png'], 'title' => 'Solo'],
            ],
            settings: ['style' => 'swipe', 'rotate_ms' => 2800, 'height' => 260],
            style: 'swipe',
        );

        $this->assertStringContainsString('ml-mswipe', $markup);
        $this->assertStringContainsString('--ml-sh:260px', $markup);
        $this->assertStringContainsString('data-rotate="2800"', $markup);
        $this->assertStringContainsString('ml-tile--square', $markup);
        $this->assertSame(2, substr_count($markup, 'ml-tile__frame'), 'both frames of the pair must be in the DOM');
        $this->assertStringContainsString('is-on', $markup);
    }

    public function test_a_single_picture_tile_never_claims_to_rotate(): void
    {
        // data-rotate is what the crossfade script binds to; emitting it for a lone image would
        // start a timer that swaps one picture for itself forever.
        $markup = $this->renderGrid(
            cards: [['image' => 'solo.png', 'images' => ['solo.png'], 'title' => 'Solo']],
            settings: ['style' => 'tiles'],
            style: 'tiles',
        );

        $this->assertStringNotContainsString('data-rotate', $markup);
        $this->assertStringNotContainsString('ml-tile--frames', $markup);
        $this->assertStringContainsString('ml-mosaic', $markup);
    }

    public function test_a_rotating_row_has_somewhere_to_rotate_to(): void
    {
        // The markup is only half the feature: without the crossfade rule and the script that
        // reads data-rotate, every extra frame would stack invisibly on top of the first.
        $home = (string) file_get_contents(resource_path('views/theme-sections/home.blade.php'));

        $this->assertStringContainsString('.ml-tile--frames', $home);
        $this->assertStringContainsString('.ml-mswipe', $home);
        $this->assertStringContainsString('dataset.rotate', $home);
    }

    private function renderGrid(array $cards, array $settings, string $style): string
    {
        return view('theme-sections.partials.banner-grid', [
            'cards' => $cards,
            'settings' => $settings,
            'style' => $style,
            'columns' => 3,
            'gap' => 16,
            'placeholder' => 'placeholder.png',
        ])->render();
    }
}
