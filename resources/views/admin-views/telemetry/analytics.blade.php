@extends('layouts.admin.app')

@section('title', translate('analytics'))

@section('content')
    <div class="content container-fluid k">
        <x-k.page-header :title="translate('analytics')"
                         :subtitle="translate('visits_sources_products_vendors_and_api_load_from_your_own_telemetry')">
            <x-slot:actions>
                <nav class="k-tabs">
                    @foreach ([7 => translate('7_days'), 30 => translate('30_days'), 90 => translate('90_days')] as $value => $label)
                        <a class="k-tab" aria-selected="{{ $range === $value ? 'true' : 'false' }}"
                           href="{{ route('admin.analytics.index', ['range' => $value]) }}">{{ $label }}</a>
                    @endforeach
                </nav>
            </x-slot:actions>
        </x-k.page-header>

        <div class="k-stats" style="margin-block-end:16px">
            <x-k.stat :label="translate('visitors')" :value="number_format($data['totals']['visitors'])" icon="customers" />
            <x-k.stat :label="translate('page_views')" :value="number_format($data['totals']['web_hits'])" icon="eye" />
            <x-k.stat :label="translate('api_requests')" :value="number_format($data['totals']['api_hits'])" icon="external" />
            <x-k.stat :label="translate('orders')" :value="number_format($data['totals']['orders'])" icon="orders" />
            <x-k.stat :label="translate('revenue')" :value="webCurrencyConverter(amount: $data['totals']['revenue'])" icon="reports" />
        </div>

        <x-k.card :title="translate('daily_trend')" style="margin-block-end:16px">
            @php
                $trend = $data['trend'];
                $maxWeb = max(1, max(array_column($trend, 'web')));
                $maxOrders = max(1, max(array_column($trend, 'orders')));
                $width = 900; $height = 200; $count = max(1, count($trend));
                $step = $count > 1 ? $width / ($count - 1) : $width;
                $pointsFor = function (string $field, int $max) use ($trend, $step, $height) {
                    $points = [];
                    foreach (array_values($trend) as $index => $day) {
                        $points[] = round($index * $step, 1) . ',' . round($height - ($day[$field] / $max) * ($height - 12) - 4, 1);
                    }
                    return implode(' ', $points);
                };
            @endphp
            {{-- Inline SVG, no charting library: two normalised series, views and orders. --}}
            <div class="k-table-wrap">
                <svg viewBox="0 0 {{ $width }} {{ $height + 24 }}" role="img" preserveAspectRatio="none"
                     aria-label="{{ translate('daily_trend') }}" style="inline-size:100%;block-size:230px;display:block">
                    <polyline fill="none" stroke="var(--k-accent)" stroke-width="2.5" points="{{ $pointsFor('web', $maxWeb) }}" />
                    <polyline fill="none" stroke="var(--k-text-muted)" stroke-width="1.5" stroke-dasharray="5 4" points="{{ $pointsFor('orders', $maxOrders) }}" />
                    @foreach (array_values($trend) as $index => $day)
                        <circle cx="{{ round($index * $step, 1) }}" cy="{{ round($height - ($day['web'] / $maxWeb) * ($height - 12) - 4, 1) }}" r="3" fill="var(--k-accent)">
                            <title>{{ $day['date'] }}: {{ number_format($day['web']) }} {{ translate('views') }}, {{ number_format($day['orders']) }} {{ translate('orders') }}</title>
                        </circle>
                    @endforeach
                    <text x="0" y="{{ $height + 18 }}" fill="var(--k-text-muted)" font-size="11">{{ $trend[0]['date'] ?? '' }}</text>
                    <text x="{{ $width }}" y="{{ $height + 18 }}" fill="var(--k-text-muted)" font-size="11" text-anchor="end">{{ end($trend)['date'] ?? '' }}</text>
                </svg>
            </div>
            <div class="k-row" style="gap:16px;margin-block-start:8px">
                <span class="k-text-subtle"><span style="display:inline-block;inline-size:14px;block-size:3px;background:var(--k-accent);vertical-align:middle"></span> {{ translate('page_views') }}</span>
                <span class="k-text-subtle"><span style="display:inline-block;inline-size:14px;block-size:3px;background:var(--k-text-muted);vertical-align:middle"></span> {{ translate('orders') }} ({{ translate('normalised') }})</span>
            </div>
        </x-k.card>

        <div class="row g-3" style="margin-block-end:16px">
            <div class="col-lg-4">
                <x-k.card :title="translate('traffic_sources')">
                    <table class="k-table">
                        <tbody>
                        @forelse ($data['sources'] as $source)
                            <tr>
                                <td>{{ $source['key'] === 'direct' ? translate('direct') : $source['key'] }}</td>
                                <td class="k-table__num"><span class="k-num">{{ number_format($source['hits']) }}</span></td>
                            </tr>
                        @empty
                            <tr><td>{{ translate('no_data_yet') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </x-k.card>
            </div>
            <div class="col-lg-4">
                <x-k.card :title="translate('campaigns') . ' (UTM)'">
                    <table class="k-table">
                        <tbody>
                        @forelse ($data['utm_sources'] as $utm)
                            <tr>
                                <td>{{ $utm['key'] }}</td>
                                <td class="k-table__num"><span class="k-num">{{ number_format($utm['hits']) }}</span></td>
                            </tr>
                        @empty
                            <tr><td>{{ translate('no_campaign_traffic_yet') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </x-k.card>
            </div>
            <div class="col-lg-4">
                <x-k.card :title="translate('devices')">
                    <table class="k-table">
                        <tbody>
                        @forelse ($data['devices'] as $device)
                            <tr>
                                <td>{{ translate($device['key']) }}</td>
                                <td class="k-table__num"><span class="k-num">{{ number_format($device['hits']) }}</span></td>
                            </tr>
                        @empty
                            <tr><td>{{ translate('no_data_yet') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </x-k.card>
            </div>
        </div>

        <div class="row g-3" style="margin-block-end:16px">
            <div class="col-lg-6">
                <x-k.card :title="translate('most_visited_products')">
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                            <tr>
                                <th>{{ translate('product') }}</th>
                                <th class="k-table__num">{{ translate('views') }}</th>
                                <th class="k-table__num">{{ translate('visitors') }}</th>
                                <th class="k-table__num">{{ translate('orders') }}</th>
                                <th class="k-table__num">{{ translate('conversion') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($data['products'] as $product)
                                <tr>
                                    <td>
                                        <a class="k-truncate" style="display:block;max-inline-size:240px"
                                           href="{{ route('admin.products.view', ['addedBy' => 'in-house', 'id' => $product['id']]) }}"
                                           title="{{ $product['name'] }}">{{ $product['name'] }}</a>
                                    </td>
                                    <td class="k-table__num"><span class="k-num">{{ number_format($product['views']) }}</span></td>
                                    <td class="k-table__num"><span class="k-num">{{ number_format($product['visitors']) }}</span></td>
                                    <td class="k-table__num"><span class="k-num">{{ number_format($product['orders']) }}</span></td>
                                    <td class="k-table__num"><span class="k-num">{{ $product['conversion_pct'] !== null ? $product['conversion_pct'] . '%' : '—' }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="5">{{ translate('no_product_visits_recorded_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-k.card>
            </div>
            <div class="col-lg-6">
                <x-k.card :title="translate('vendor_shops_by_visits')">
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                            <tr>
                                <th>{{ translate('shop') }}</th>
                                <th class="k-table__num">{{ translate('visits') }}</th>
                                <th class="k-table__num">{{ translate('orders') }}</th>
                                <th class="k-table__num">{{ translate('revenue') }}</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($data['shops'] as $shop)
                                <tr>
                                    <td>
                                        <span class="k-truncate" style="display:block;max-inline-size:200px" title="{{ $shop['name'] }}">{{ $shop['name'] }}</span>
                                    </td>
                                    <td class="k-table__num"><span class="k-num">{{ number_format($shop['visits']) }}</span></td>
                                    <td class="k-table__num"><span class="k-num">{{ number_format($shop['orders']) }}</span></td>
                                    <td class="k-table__num"><span class="k-num">{{ webCurrencyConverter(amount: $shop['revenue']) }}</span></td>
                                    <td>
                                        <div class="k-table__actions">
                                            <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('view_vendor') }}"
                                               href="{{ route('admin.vendors.view', ['id' => $shop['seller_id']]) }}">
                                                <x-k.icon name="eye" :size="15" />
                                            </a>
                                            <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('open_shop_page') }}"
                                               target="_blank" rel="noopener" href="{{ route('vendor-shop', ['slug' => $shop['slug']]) }}">
                                                <x-k.icon name="external" :size="15" />
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5">{{ translate('no_shop_visits_recorded_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-k.card>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <x-k.card :title="translate('top_pages')">
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                            <tr>
                                <th>{{ translate('path') }}</th>
                                <th class="k-table__num">{{ translate('hits') }}</th>
                                <th class="k-table__num">{{ translate('visitors') }}</th>
                                <th class="k-table__num">{{ translate('avg') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($data['paths'] as $path)
                                <tr>
                                    <td><span class="k-truncate" style="display:block;max-inline-size:260px">/{{ $path['key'] === '/' ? '' : $path['key'] }}</span></td>
                                    <td class="k-table__num"><span class="k-num">{{ number_format($path['hits']) }}</span></td>
                                    <td class="k-table__num"><span class="k-num">{{ number_format($path['visitors']) }}</span></td>
                                    <td class="k-table__num"><span class="k-num">{{ $path['avg_ms'] !== null ? $path['avg_ms'] . ' ms' : '—' }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="4">{{ translate('no_data_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-k.card>
            </div>
            <div class="col-lg-6">
                <x-k.card :title="translate('api_endpoints') . ' — ' . translate('customer_and_vendor_apps')">
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                            <tr>
                                <th>{{ translate('endpoint_group') }}</th>
                                <th class="k-table__num">{{ translate('hits') }}</th>
                                <th class="k-table__num">{{ translate('errors') }}</th>
                                <th class="k-table__num">{{ translate('avg') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($data['api_groups'] as $group)
                                <tr>
                                    <td><code>{{ $group['key'] }}</code></td>
                                    <td class="k-table__num"><span class="k-num">{{ number_format($group['hits']) }}</span></td>
                                    <td class="k-table__num">
                                        @if ($group['errors'] > 0)
                                            <x-k.badge tone="danger"><span class="k-num">{{ number_format($group['errors']) }}</span></x-k.badge>
                                        @else
                                            <span class="k-num">0</span>
                                        @endif
                                    </td>
                                    <td class="k-table__num"><span class="k-num">{{ $group['avg_ms'] !== null ? $group['avg_ms'] . ' ms' : '—' }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="4">{{ translate('no_api_traffic_recorded_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-k.card>
            </div>
        </div>
    </div>
@endsection
