<?php

namespace App\Services\Theme;

/**
 * Catalogue of the section types the storefront can render, and the settings each one exposes
 * (Phase 1.2 — Theme Builder).
 *
 * The registry is the contract between the builder UI (which renders a settings form from the
 * schema) and the storefront (which renders the section). Adding a new section type is a matter of
 * adding an entry here plus its blade partial — no builder code changes — which is the
 * extensibility the brief asked for.
 */
class SectionRegistry
{
    /** Field types the builder UI knows how to render. */
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'boolean', 'select', 'color', 'image', 'link', 'source'];

    /**
     * Settings shared by every section, so spacing/width/background behave consistently.
     * Responsive-capable keys are marked so the UI can offer desktop/tablet/mobile values.
     */
    public function commonSchema(): array
    {
        return [
            'background'     => ['type' => 'color',   'label' => 'background',      'default' => null],
            'padding_top'    => ['type' => 'number',  'label' => 'padding_top',     'default' => 40, 'responsive' => true],
            'padding_bottom' => ['type' => 'number',  'label' => 'padding_bottom',  'default' => 40, 'responsive' => true],
            'width'          => ['type' => 'select',  'label' => 'width',           'default' => 'container',
                                 'options' => ['container', 'full']],
            'alignment'      => ['type' => 'select',  'label' => 'alignment',       'default' => 'start',
                                 'options' => ['start', 'center', 'end']],
            'visible'        => ['type' => 'boolean', 'label' => 'visible',         'default' => true, 'responsive' => true],
        ];
    }

    /**
     * Section type definitions: page it belongs to, label, and its own settings schema.
     * @return array<string, array>
     */
    public function types(): array
    {
        return [
            'announcement_bar' => [
                'label' => 'announcement_bar', 'pages' => ['header'],
                'schema' => [
                    'text'      => ['type' => 'text', 'label' => 'text', 'default' => ''],
                    'link'      => ['type' => 'link', 'label' => 'link', 'default' => null],
                    'dismissible' => ['type' => 'boolean', 'label' => 'dismissible', 'default' => true],
                ],
            ],
            'hero_banner' => [
                'label' => 'hero_banner', 'pages' => ['home'], 'blocks' => ['slide'],
                'schema' => [
                    'autoplay' => ['type' => 'boolean', 'label' => 'autoplay', 'default' => true],
                    'interval' => ['type' => 'number',  'label' => 'interval_ms', 'default' => 5000],
                    'height'   => ['type' => 'number',  'label' => 'height', 'default' => 420, 'responsive' => true],
                ],
            ],
            'category_grid' => [
                'label' => 'category_grid', 'pages' => ['home'],
                'schema' => [
                    'title'   => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'limit'   => ['type' => 'number', 'label' => 'max_items', 'default' => 12],
                    'columns' => ['type' => 'number', 'label' => 'columns', 'default' => 6, 'responsive' => true],
                ],
            ],
            'product_slider' => [
                'label' => 'product_slider', 'pages' => ['home'],
                'schema' => [
                    'title'       => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'subtitle'    => ['type' => 'text',   'label' => 'subtitle', 'default' => ''],
                    'source'      => ['type' => 'source', 'label' => 'product_source', 'default' => 'featured',
                                      'options' => ['featured', 'best_selling', 'new_arrival', 'top_rated', 'category', 'brand', 'manual']],
                    'source_id'   => ['type' => 'number', 'label' => 'source_reference', 'default' => null],
                    'limit'       => ['type' => 'number', 'label' => 'max_products', 'default' => 10],
                    'columns'     => ['type' => 'number', 'label' => 'columns', 'default' => 5, 'responsive' => true],
                    'autoplay'    => ['type' => 'boolean','label' => 'autoplay', 'default' => false],
                    'arrows'      => ['type' => 'boolean','label' => 'navigation_arrows', 'default' => true],
                    'pagination'  => ['type' => 'boolean','label' => 'pagination', 'default' => false],
                    'view_all'    => ['type' => 'boolean','label' => 'view_all_button', 'default' => true],
                ],
            ],
            'promotional_banner' => [
                'label' => 'promotional_banner', 'pages' => ['home'], 'blocks' => ['banner'],
                'schema' => ['columns' => ['type' => 'number', 'label' => 'columns', 'default' => 2, 'responsive' => true]],
            ],
            'brand_slider' => [
                'label' => 'brand_slider', 'pages' => ['home'],
                'schema' => [
                    'title' => ['type' => 'text',   'label' => 'title', 'default' => ''],
                    'limit' => ['type' => 'number', 'label' => 'max_items', 'default' => 12],
                ],
            ],
            'flash_deal' => [
                'label' => 'flash_deals', 'pages' => ['home'],
                'schema' => [
                    'title'     => ['type' => 'text',    'label' => 'title', 'default' => ''],
                    'countdown' => ['type' => 'boolean', 'label' => 'show_countdown', 'default' => true],
                ],
            ],
            'custom_html' => [
                'label' => 'custom_content', 'pages' => ['home', 'footer'],
                'schema' => ['content' => ['type' => 'textarea', 'label' => 'content', 'default' => '']],
            ],
            'newsletter' => [
                'label' => 'newsletter', 'pages' => ['home', 'footer'],
                'schema' => [
                    'title'    => ['type' => 'text', 'label' => 'title', 'default' => ''],
                    'subtitle' => ['type' => 'text', 'label' => 'subtitle', 'default' => ''],
                ],
            ],
            'spacer' => [
                'label' => 'spacer', 'pages' => ['home'],
                'schema' => ['height' => ['type' => 'number', 'label' => 'height', 'default' => 40, 'responsive' => true]],
            ],
            'footer_columns' => [
                'label' => 'footer_columns', 'pages' => ['footer'], 'blocks' => ['menu', 'text', 'contact', 'social', 'apps'],
                'schema' => ['columns' => ['type' => 'number', 'label' => 'columns', 'default' => 4, 'responsive' => true]],
            ],
        ];
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->types());
    }

    /** Section types available for a given page (home/header/footer). */
    public function forPage(string $page): array
    {
        return array_filter($this->types(), fn ($def) => in_array($page, $def['pages'] ?? [], true));
    }

    /** Full schema for a type: its own fields plus the common ones. */
    public function schemaFor(string $type): array
    {
        $types = $this->types();
        if (!isset($types[$type])) {
            return [];
        }
        return array_merge($types[$type]['schema'] ?? [], $this->commonSchema());
    }

    /**
     * Validate and normalize a settings payload against the type's schema: unknown keys are
     * dropped (so the builder can never write arbitrary data), missing keys get defaults, and
     * values are coerced to the declared type.
     */
    public function normalizeSettings(string $type, array $settings): array
    {
        $schema = $this->schemaFor($type);
        $clean = [];

        foreach ($schema as $key => $field) {
            $clean[$key] = $this->coerce($settings[$key] ?? $field['default'] ?? null, $field);

            // Responsive overrides: settings may carry key_tablet / key_mobile variants.
            //
            // These get the SAME coercion as the base value. They used to be copied through raw,
            // which meant an override reached the storefront unvalidated while the base value was
            // sanitised — the one path an attacker-controlled or corrupted value could take.
            //
            // An absent override is left absent rather than defaulted: the renderer treats a
            // missing breakpoint key as "inherit the base value", so writing a default here would
            // silently pin every section to its desktop value on tablet and mobile.
            if (!empty($field['responsive'])) {
                foreach (['tablet', 'mobile'] as $breakpoint) {
                    $rKey = $key . '_' . $breakpoint;
                    if (!array_key_exists($rKey, $settings)) {
                        continue;
                    }
                    if ($settings[$rKey] === null || $settings[$rKey] === '') {
                        continue; // explicit "inherit"
                    }
                    $clean[$rKey] = $this->coerce($settings[$rKey], $field);
                }
            }
        }

        return $clean;
    }

    /** Coerce one value to the type its schema field declares. */
    private function coerce(mixed $value, array $field): mixed
    {
        return match ($field['type']) {
            'number'  => is_numeric($value) ? $value + 0 : ($field['default'] ?? null),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) ($field['default'] ?? false),
            'select', 'source' => in_array($value, $field['options'] ?? [], true) ? $value : ($field['default'] ?? null),
            default   => is_scalar($value) ? (string) $value : ($field['default'] ?? null),
        };
    }
}
