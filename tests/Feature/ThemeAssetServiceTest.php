<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeAsset;
use App\Services\Theme\ThemeAssetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Theme asset uploads. Most of these assert what the service REFUSES — this codebase already
 * shipped an arbitrary-file-write through ImageManager, so the upload path is the one to distrust.
 */
class ThemeAssetServiceTest extends TestCase
{
    private ThemeAssetService $svc;
    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->svc = new ThemeAssetService();

        Schema::dropIfExists('theme_assets');
        Schema::dropIfExists('themes');
        Schema::create('themes', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('slug', 120)->unique();
            $t->string('description', 500)->nullable(); $t->string('preview_image', 2048)->nullable();
            $t->boolean('is_active')->default(false); $t->boolean('is_system')->default(false);
            $t->string('created_by_type', 40)->nullable(); $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
        Schema::create('theme_assets', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('theme_id'); $t->string('label')->nullable();
            $t->string('path', 2048); $t->string('disk', 40)->default('public');
            $t->string('mime_type', 100)->nullable(); $t->unsignedBigInteger('size_bytes')->default(0);
            $t->string('uploaded_by_type', 40)->nullable(); $t->unsignedBigInteger('uploaded_by_id')->nullable();
            $t->timestamps();
        });

        $this->theme = Theme::create(['name' => 'A', 'slug' => 'a']);
    }

    public function test_accepts_a_real_image(): void
    {
        $r = $this->svc->upload($this->theme, UploadedFile::fake()->image('logo.png', 60, 60), 'Logo', 1);

        $this->assertNull($r['error']);
        $this->assertInstanceOf(ThemeAsset::class, $r['asset']);
        Storage::disk('public')->assertExists($r['asset']->path);
    }

    /** The core protection: a PHP payload disguised by filename must never be stored. */
    public function test_rejects_a_php_file_disguised_as_an_image(): void
    {
        $file = UploadedFile::fake()->createWithContent('evil.png', '<?php system($_GET["c"]); ?>');

        $r = $this->svc->upload($this->theme, $file);

        $this->assertNotNull($r['error']);
        $this->assertNull($r['asset']);
        $this->assertSame(0, ThemeAsset::count());
    }

    /** The stored extension comes from the DETECTED type, never the client's filename. */
    public function test_stored_extension_is_derived_from_content_not_filename(): void
    {
        $r = $this->svc->upload($this->theme, UploadedFile::fake()->image('payload.php.png', 20, 20));

        $this->assertNull($r['error']);
        $this->assertStringEndsWith('.png', $r['asset']->path);
        $this->assertStringNotContainsString('.php', $r['asset']->path);
    }

    public function test_filename_is_generated_so_traversal_is_impossible(): void
    {
        $r = $this->svc->upload($this->theme, UploadedFile::fake()->image('../../../etc/passwd.png', 20, 20));

        $this->assertNull($r['error']);
        $this->assertStringNotContainsString('..', $r['asset']->path);
        $this->assertStringContainsString('theme-assets/' . $this->theme->id, $r['asset']->path);
    }

    public function test_rejects_files_over_the_size_limit(): void
    {
        $big = UploadedFile::fake()->create('big.png', 6 * 1024, 'image/png'); // 6 MB

        $this->assertNotNull($this->svc->upload($this->theme, $big)['error']);
    }

    public function test_rejects_a_non_image_mime(): void
    {
        $r = $this->svc->upload($this->theme, UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'));

        $this->assertSame('only_image_files_are_allowed', $r['error']);
    }

    // ---- SVG: an XSS vector because it is served inline ----

    public function test_rejects_svg_containing_script(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->assertFalse($this->svc->isSafeSvg(UploadedFile::fake()->createWithContent('x.svg', $svg)));
    }

    public function test_rejects_svg_with_an_event_handler(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>';
        $this->assertFalse($this->svc->isSafeSvg(UploadedFile::fake()->createWithContent('x.svg', $svg)));
    }

    public function test_rejects_svg_with_foreignobject_or_iframe(): void
    {
        foreach (['<svg><foreignObject><b>x</b></foreignObject></svg>', '<svg><iframe src="x"></iframe></svg>'] as $svg) {
            $this->assertFalse($this->svc->isSafeSvg(UploadedFile::fake()->createWithContent('x.svg', $svg)), $svg);
        }
    }

    public function test_accepts_a_plain_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';
        $this->assertTrue($this->svc->isSafeSvg(UploadedFile::fake()->createWithContent('ok.svg', $svg)));
    }

    public function test_delete_removes_both_the_row_and_the_file(): void
    {
        $asset = $this->svc->upload($this->theme, UploadedFile::fake()->image('l.png', 20, 20))['asset'];
        $path = $asset->path;

        $this->assertTrue($this->svc->delete($asset));

        Storage::disk('public')->assertMissing($path);
        $this->assertSame(0, ThemeAsset::count());
    }

    public function test_delete_still_removes_the_row_when_the_file_is_already_gone(): void
    {
        $asset = $this->svc->upload($this->theme, UploadedFile::fake()->image('l.png', 20, 20))['asset'];
        Storage::disk('public')->delete($asset->path);

        $this->assertTrue($this->svc->delete($asset));
        $this->assertSame(0, ThemeAsset::count());
    }

    // ---- display ----

    /**
     * A 197-byte favicon rounded to KB shows "0 KB", which a merchant reads as a failed upload.
     * Caught on the rendered admin page, not in a unit test.
     */
    public function test_small_files_are_not_displayed_as_zero_kb(): void
    {
        $cases = [
            197              => '197 B',
            1024             => '1 KB',
            1536             => '1.5 KB',
            5 * 1024 * 1024  => '5 MB',
        ];

        foreach ($cases as $bytes => $expected) {
            $asset = new ThemeAsset(['size_bytes' => $bytes]);
            $this->assertSame($expected, $asset->size_for_humans, "for {$bytes} bytes");
        }
    }

    public function test_url_is_null_rather_than_fatal_for_an_unknown_disk(): void
    {
        $asset = new ThemeAsset(['path' => 'x.png', 'disk' => 'a-disk-that-does-not-exist']);

        $this->assertNull($asset->url); // a broken asset must not 500 the theme page
    }

    /** The label is bounded so a long paste cannot overflow the column or the layout. */
    public function test_label_is_trimmed_and_truncated(): void
    {
        $r = $this->svc->upload($this->theme, UploadedFile::fake()->image('l.png', 20, 20), '  ' . str_repeat('x', 400) . '  ');

        $this->assertLessThanOrEqual(120, mb_strlen($r['asset']->label));
        $this->assertSame(trim($r['asset']->label), $r['asset']->label);
    }
}
