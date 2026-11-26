{{-- Серверный fallback для всех семейств AI-виджетов. JS заменяет его на ApexCharts,
     но числа и подписи уже присутствуют в HTML на первой отрисовке. --}}
@php
    $family = (string) ($family ?? '');
    $catalog = app(\App\Domains\DashboardGen\Services\WidgetCatalog::class);
    $type = $type ?: $catalog->defaultType($family);
    $shape = \App\Domains\DashboardGen\Services\WidgetShapeMapper::shapeFor($family);
    $content = is_array($content ?? null) ? $content : [];

    if (! function_exists('bi_ssr_number')) {
        // тот же контракт, что и formatCell() в bi-widgets.js: до двух знаков,
        // без хвостовых нулей, с пробелом как разделителем разрядов.
        function bi_ssr_number(mixed $value): string
        {
            $formatted = number_format((float) $value, 2, ',', ' ');

            return str_contains($formatted, ',')
                ? rtrim(rtrim($formatted, '0'), ',')
                : $formatted;
        }
    }

    if (! function_exists('bi_ssr_text')) {
        function bi_ssr_text(mixed $value): string
        {
            $value = $value === null || $value === '' ? '—' : (string) $value;

            return mb_strlen($value) > 80 ? mb_substr($value, 0, 77).'…' : $value;
        }
    }

    if (! function_exists('bi_ssr_affix')) {
        function bi_ssr_affix(mixed $value): string
        {
            return $value === null || $value === '' ? '' : mb_substr((string) $value, 0, 8);
        }
    }

    $items = [];
    $matrix = [];
    $matrixAxis = [];
    $tableHeaders = [];
    $tableRows = [];
    $counters = [];
    $shownCount = 0;
    $availableCount = 0;

    if ($shape === \App\Domains\DashboardGen\Services\WidgetShapeMapper::SHAPE_COUNTERS) {
        $allCounters = is_array($content['counters'] ?? null) ? $content['counters'] : [];
        $counters = array_slice($allCounters, 0, 12);
        $shownCount = count($counters);
        $availableCount = count($allCounters);
    } elseif ($shape === \App\Domains\DashboardGen\Services\WidgetShapeMapper::SHAPE_ROWS) {
        $tableHeaders = is_array($content['headers'] ?? null) ? array_slice($content['headers'], 0, 10) : [];
        $allRows = is_array($content['rows'] ?? null) ? $content['rows'] : [];
        $tableRows = array_slice($allRows, 0, 12);
        $shownCount = count($tableRows);
        $availableCount = count($allRows);
    } elseif ($shape === \App\Domains\DashboardGen\Services\WidgetShapeMapper::SHAPE_SERIES_VALUES) {
        $series = is_array($content['series'] ?? null) ? $content['series'] : [];
        $labels = is_array($content['labels'] ?? null) ? $content['labels'] : [];

        if ($family === 'treemap' && isset($series[0]['data']) && is_array($series[0]['data'])) {
            $points = $series[0]['data'];
            foreach (array_slice($points, 0, 12) as $point) {
                $items[] = [
                    'label' => bi_ssr_text($point['x'] ?? null),
                    'value' => (float) ($point['y'] ?? 0),
                ];
            }
            $shownCount = count($items);
            $availableCount = count($points);
        } else {
            foreach (array_slice($series, 0, 12) as $index => $value) {
                $items[] = [
                    'label' => bi_ssr_text($labels[$index] ?? null),
                    'value' => (float) $value,
                ];
            }
            $shownCount = count($items);
            $availableCount = count($series);
        }
    } elseif ($shape === \App\Domains\DashboardGen\Services\WidgetShapeMapper::SHAPE_POINTS) {
        foreach (is_array($content['series'] ?? null) ? $content['series'] : [] as $serie) {
            $points = is_array($serie['data'] ?? null) ? $serie['data'] : [];
            foreach (array_slice($points, 0, 12) as $point) {
                $items[] = [
                    'label' => bi_ssr_text(($serie['name'] ?? 'Точка').' · x='.($point[0] ?? '—')),
                    'value' => (float) ($point[1] ?? 0),
                ];
            }
            $shownCount = count($items);
            $availableCount += count($points);
        }
    } elseif ($family === 'heatmap') {
        foreach (is_array($content['series'] ?? null) ? $content['series'] : [] as $serie) {
            $points = is_array($serie['data'] ?? null) ? $serie['data'] : [];
            foreach (array_slice($points, 0, 12) as $point) {
                $items[] = [
                    'label' => bi_ssr_text(($serie['name'] ?? '').' · '.($point['x'] ?? '')),
                    'value' => (float) ($point['y'] ?? 0),
                ];
            }
            $shownCount = count($items);
            $availableCount += count($points);
        }
    } elseif ($shape === \App\Domains\DashboardGen\Services\WidgetShapeMapper::SHAPE_SERIES_MATRIX) {
        $matrixAxis = is_array($content['categories'] ?? null)
            ? $content['categories']
            : (is_array($content['labels'] ?? null) ? $content['labels'] : []);
        $allSeries = is_array($content['series'] ?? null) ? $content['series'] : [];
        $matrix = array_slice($allSeries, 0, 8);
        $shownCount = count($matrix);
        $availableCount = count($allSeries);
    }

    $peak = 1.0;
    foreach ($items as $item) {
        $peak = max($peak, abs((float) $item['value']));
    }

    $isEmpty = $counters === [] && $tableRows === [] && $items === [] && $matrix === [];
@endphp

@if ($isEmpty)
    <div class="bi-empty" data-bi-ssr="empty">{{ __('Нет данных') }}</div>
@else
    <div class="bi-ssr bi-ssr--{{ $family }}" data-bi-ssr="{{ $family }}" data-bi-ssr-type="{{ $type }}">

        @if ($counters !== [])
            <div class="bi-ssr__counters">
                @foreach ($counters as $counter)
                    <div class="bi-counter-card">
                        <span class="bi-counter-name">{{ bi_ssr_text($counter['name'] ?? null) }}</span>
                        <strong class="bi-counter-value">
                            {{ bi_ssr_affix($counter['prefix'] ?? null) }}{{ bi_ssr_number($counter['value'] ?? 0) }}{{ bi_ssr_affix($counter['suffix'] ?? null) }}
                        </strong>
                        @if (isset($counter['percent']) && is_numeric($counter['percent']))
                            <span class="progress" style="height:4px" role="progressbar"
                                  aria-valuenow="{{ max(0, min(100, (float) $counter['percent'])) }}" aria-valuemin="0" aria-valuemax="100">
                                <span class="progress-bar" style="width:{{ max(0, min(100, (float) $counter['percent'])) }}%"></span>
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>

        @elseif ($tableRows !== [])
            <div class="bi-ssr__table table-responsive">
                <table class="table table-vcenter card-table {{ $type === 'compact' ? 'table-sm' : '' }} {{ $type === 'striped' ? 'table-striped' : '' }}">
                    @if ($tableHeaders !== [])
                        <thead><tr>@foreach ($tableHeaders as $header)<th>{{ bi_ssr_text($header) }}</th>@endforeach</tr></thead>
                    @endif
                    <tbody>
                        @foreach ($tableRows as $row)
                            <tr>@foreach ($tableHeaders as $index => $header)<td>{{ bi_ssr_text(is_array($row) ? ($row[$index] ?? null) : null) }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @elseif ($matrix !== [])
            <div class="bi-ssr__matrix table-responsive">
                <table class="table table-vcenter card-table table-sm">
                    <thead><tr><th>{{ __('Ряд') }}</th>@foreach (array_slice($matrixAxis, 0, 12) as $axis)<th>{{ bi_ssr_text($axis) }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($matrix as $serie)
                            <tr>
                                <th>{{ bi_ssr_text($serie['name'] ?? null) }}</th>
                                @foreach (array_slice(is_array($serie['data'] ?? null) ? $serie['data'] : [], 0, 12) as $value)
                                    <td>{{ bi_ssr_number($value) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @else
            <ul class="bi-ssr__list">
                @foreach ($items as $item)
                    <li>
                        <span class="bi-ssr__label">{{ $item['label'] }}</span>
                        <span class="bi-ssr__bar" aria-hidden="true"><i style="width:{{ round(abs($item['value']) / $peak * 100, 2) }}%"></i></span>
                        <strong class="bi-ssr__value">{{ bi_ssr_number($item['value']) }}</strong>
                    </li>
                @endforeach
            </ul>
        @endif

    </div>

    @if ($availableCount > $shownCount)
        <div class="bi-notice">{{ __('Показано') }} {{ $shownCount }} {{ __('из') }} {{ $availableCount }} {{ __('точек') }}</div>
    @endif
@endif
