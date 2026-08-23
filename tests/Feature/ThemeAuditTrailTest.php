<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemeManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Spec §49: every action that changes what customers see leaves a line saying who and when —
 * through the system-wide AuditLogger, never a theme-private log.
 */
class ThemeAuditTrailTest extends TestCase
{
    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach (['audit_logs', 'theme_blocks', 'theme_sections', 'theme_versions', 'themes'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('themes', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(false); $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_id'); $table->string('label')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(0); $table->string('checksum', 64)->nullable();
            $table->json('settings')->nullable(); $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('published_at')->nullable(); $table->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('theme_version_id'); $table->string('page', 60)->default('home');
            $table->string('type', 80); $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->json('platforms')->nullable(); $table->json('audience')->nullable();
            $table->json('settings')->nullable(); $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_section_id'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable(); $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type')->nullable(); $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable(); $table->string('action');
            $table->string('subject_type')->nullable(); $table->string('subject_id')->nullable();
            $table->json('before')->nullable(); $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable(); $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        $this->theme = Theme::create(['name' => 'Pharmacy', 'slug' => 'pharmacy', 'is_active' => true]);
    }

    private function actions(): array
    {
        return AuditLog::query()->orderBy('id')->pluck('action')->all();
    }

    public function test_the_lifecycle_leaves_a_trail(): void
    {
        $manager = new ThemeManager();
        $builder = app(ThemeBuilderService::class);

        $draft = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);

        $section = $builder->addSection($draft, 'home', 'spacer');
        $builder->updateSection($section, ['height' => 60]);
        $builder->setDeliveryRules($section, ['platforms' => ['app']]);
        $builder->reorderSections($draft, 'home', [$section->id]);

        $manager->publish($draft->refresh());
        $manager->restoreVersion($draft->refresh());
        $builder->deleteSection($section->fresh());

        // deleteSection refuses on the now-published version — that refusal must not be audited
        // as if it happened.
        $this->assertNotContains('theme.section_deleted', $this->actions());

        $this->assertSame([
            'theme.section_added',
            'theme.section_updated',
            'theme.delivery_rules_updated',
            'theme.sections_reordered',
            'theme.published',
            'theme.restored',
        ], $this->actions());
    }

    public function test_updates_carry_before_and_after(): void
    {
        $builder = app(ThemeBuilderService::class);
        $draft = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
        $section = $builder->addSection($draft, 'home', 'spacer');

        $builder->updateSection($section, ['height' => 99]);

        $line = AuditLog::query()->where('action', 'theme.section_updated')->first();
        $this->assertNotNull($line);
        $this->assertSame(99, $line->after['settings']['height']);
        $this->assertNotSame($line->before, $line->after,
            'the trail must show what changed, not only that something did');
    }

    public function test_a_refused_edit_is_not_audited(): void
    {
        $builder = app(ThemeBuilderService::class);
        $version = ThemeVersion::create(['theme_id' => $this->theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED]);
        $section = ThemeSection::create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer', 'settings' => []]);

        $builder->updateSection($section, ['height' => 10]);
        $builder->setDeliveryRules($section, ['platforms' => ['app']]);
        $builder->deleteSection($section);

        $this->assertSame([], $this->actions(),
            'refused mutations on a published version must leave no trail of things that did not happen');
    }
}
