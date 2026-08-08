<?php

namespace Tests\Unit;

use App\Services\Seo\SeoAuditService;
use App\Services\Seo\SeoTemplateRenderer;
use PHPUnit\Framework\TestCase;

class SeoAuditServiceTest extends TestCase
{
    private SeoAuditService $audit;

    protected function setUp(): void
    {
        $this->audit = new SeoAuditService(new SeoTemplateRenderer());
    }

    private function codes(array $issues): array
    {
        return array_column($issues, 'code');
    }

    private function healthyEntity(): array
    {
        return [
            'title'         => 'Panadol Extra 500mg Pain Relief Tablets',
            'description'   => str_repeat('Effective relief for headache and fever. ', 3),
            'h1'            => 'Panadol Extra',
            'canonical_url' => 'https://x.test/product/panadol',
            'images'        => [['alt' => 'Panadol box']],
            'is_indexable'  => true,
            'schema'        => ['@type' => 'Product'],
        ];
    }

    public function test_healthy_entity_reports_no_issues(): void
    {
        $this->assertSame([], $this->audit->analyze($this->healthyEntity()));
    }

    public function test_missing_title_and_description_are_critical(): void
    {
        $issues = $this->audit->analyze(['title' => '', 'description' => '']);
        $codes = $this->codes($issues);

        $this->assertContains('missing_title', $codes);
        $this->assertContains('missing_description', $codes);
        foreach ($issues as $issue) {
            if (in_array($issue['code'], ['missing_title', 'missing_description'], true)) {
                $this->assertSame(SeoAuditService::SEVERITY_CRITICAL, $issue['severity']);
                $this->assertNotEmpty($issue['fix']); // every issue must explain the fix
            }
        }
    }

    public function test_detects_missing_h1_canonical_alt_and_schema(): void
    {
        $entity = $this->healthyEntity();
        $entity['h1'] = '';
        $entity['canonical_url'] = '';
        $entity['images'] = [['alt' => ''], ['alt' => 'ok']];
        $entity['schema'] = [];

        $codes = $this->codes($this->audit->analyze($entity));

        $this->assertContains('missing_h1', $codes);
        $this->assertContains('missing_canonical', $codes);
        $this->assertContains('missing_image_alt', $codes);
        $this->assertContains('missing_schema', $codes);
    }

    public function test_noindex_is_flagged_as_critical(): void
    {
        $entity = $this->healthyEntity();
        $entity['is_indexable'] = false;

        $issues = $this->audit->analyze($entity);
        $found = array_values(array_filter($issues, fn ($i) => $i['code'] === 'not_indexable'));

        $this->assertNotEmpty($found);
        $this->assertSame(SeoAuditService::SEVERITY_CRITICAL, $found[0]['severity']);
    }

    public function test_flags_overlong_title_and_description(): void
    {
        $entity = $this->healthyEntity();
        $entity['title'] = str_repeat('a', 90);
        $entity['description'] = str_repeat('b', 250);

        $codes = $this->codes($this->audit->analyze($entity));

        $this->assertContains('title_too_long', $codes);
        $this->assertContains('description_too_long', $codes);
    }

    public function test_detects_duplicates_from_context(): void
    {
        $entity = $this->healthyEntity();
        $codes = $this->codes($this->audit->analyze($entity, [
            'duplicate_titles'       => [$entity['title']],
            'duplicate_descriptions' => [$entity['description']],
        ]));

        $this->assertContains('duplicate_title', $codes);
        $this->assertContains('duplicate_description', $codes);
    }

    public function test_summarize_counts_by_severity(): void
    {
        $issues = $this->audit->analyze(['title' => '', 'description' => '', 'h1' => '']);
        $summary = $this->audit->summarize($issues);

        $this->assertSame(2, $summary[SeoAuditService::SEVERITY_CRITICAL]);
        $this->assertGreaterThanOrEqual(1, $summary[SeoAuditService::SEVERITY_WARNING]);
        $this->assertSame(count($issues), $summary['total']);
    }

    public function test_arabic_content_is_measured_by_characters(): void
    {
        $entity = $this->healthyEntity();
        $entity['title'] = str_repeat('ا', 45);          // 45 chars -> fine
        $entity['description'] = str_repeat('ب', 120);   // 120 chars -> fine

        $codes = $this->codes($this->audit->analyze($entity));

        $this->assertNotContains('title_too_long', $codes);
        $this->assertNotContains('description_too_long', $codes);
    }
}
