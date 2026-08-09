<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Renders each design-system component through Laravel's real Blade pipeline, so the shared UI
 * primitives are proven to work (and keep working) rather than assumed.
 */
class UiComponentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // translate() reads business_settings via getWebConfig().
        if (!Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $t) {
                $t->id(); $t->string('type')->nullable(); $t->text('value')->nullable(); $t->timestamps();
            });
        }
    }

    private function render(string $template, array $data = []): string
    {
        return Blade::render($template, $data);
    }

    public function test_status_badge_renders_boolean_states_as_labels(): void
    {
        $on  = $this->render('<x-ui.status-badge :state="true" />');
        $off = $this->render('<x-ui.status-badge :state="false" />');

        $this->assertStringContainsString('badge-soft-success', $on);
        $this->assertStringContainsString('badge-soft-secondary', $off);
        // meaningful labels, not repeated Yes/No
        $this->assertStringNotContainsString('Yes', $on);
    }

    public function test_status_badge_supports_custom_labels_and_tone(): void
    {
        $html = $this->render('<x-ui.status-badge :state="true" on="paid" off="unpaid" tone="info" />');

        $this->assertStringContainsString('badge-soft-info', $html);
        $this->assertStringContainsString('Paid', $html);
    }

    public function test_status_badge_renders_non_boolean_state_literally(): void
    {
        // "pending" must not be coerced into a false/inactive badge
        $html = $this->render('<x-ui.status-badge state="pending" tone="warning" />');

        $this->assertStringContainsString('badge-soft-warning', $html);
        $this->assertStringContainsString('Pending', $html);
        $this->assertStringNotContainsString('Inactive', $html);
    }

    public function test_empty_state_renders_title_and_optional_action(): void
    {
        $html = $this->render('<x-ui.empty-state title="no_redirects_yet"><a href="#">Add</a></x-ui.empty-state>');

        $this->assertStringContainsString('No redirects yet', $html);
        $this->assertStringContainsString('<a href="#">Add</a>', $html);
    }

    public function test_money_always_renders_with_currency_context_and_ltr(): void
    {
        $html = $this->render('<x-ui.money :amount="1234.5" />');

        $this->assertStringContainsString('dir="ltr"', $html);   // readable inside RTL pages
        $this->assertMatchesRegularExpression('/1,?234/', $html); // the amount is present
    }

    public function test_money_handles_non_numeric_input_safely(): void
    {
        $html = $this->render('<x-ui.money amount="not-a-number" />');
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('0', $html);
    }

    public function test_data_table_contains_horizontal_scroll_wrapper(): void
    {
        $html = $this->render('<x-ui.data-table><tbody><tr><td>x</td></tr></tbody></x-ui.data-table>');

        $this->assertStringContainsString('table-responsive', $html);
        $this->assertStringContainsString('overflow-x:auto', $html); // page body never scrolls sideways
        $this->assertStringContainsString('<td>x</td>', $html);
    }

    public function test_page_header_renders_title_and_actions(): void
    {
        $html = $this->render('<x-ui.page-header title="Theme_Management"><button>Go</button></x-ui.page-header>');

        $this->assertStringContainsString('Theme Management', $html);
        $this->assertStringContainsString('<button>Go</button>', $html);
        $this->assertStringContainsString('gap-3', $html); // flex gap, not directional margins (RTL-safe)
    }

    public function test_skeleton_renders_requested_rows_and_is_aria_hidden(): void
    {
        $html = $this->render('<x-ui.skeleton rows="4" />');

        $this->assertSame(4, substr_count($html, 'ui-skeleton__line'));
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_components_accept_extra_classes_without_losing_their_own(): void
    {
        $html = $this->render('<x-ui.status-badge :state="true" class="ms-2" />');

        $this->assertStringContainsString('badge-soft-success', $html);
        $this->assertStringContainsString('ms-2', $html);
    }
}
