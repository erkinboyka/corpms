/**
 * BI-виджеты corpms — порт реестра и компонентов Datavue на ванильный JS.
 *
 * Подключение:
 *   <script src="/assets/vendor/apexcharts.min.js"></script>
 *   <script src="/assets/js/bi-widgets.js"></script>
 *   BiWidgets.render(el, { family, type, content, options });
 *
 * window.BiWidgets предоставляет FAMILIES, prepare(), hasData(),
 * supportsColors() и render(). Никаких зависимостей, кроме глобального
 * window.ApexCharts и браузерного DOM.
 */
(function (global) {
    'use strict';

    /* --------------------------- минимальные стили -------------------------- */

    if (typeof document !== 'undefined' && !document.getElementById('bi-widgets-style')) {
        var style = document.createElement('style');
        style.id = 'bi-widgets-style';
        style.textContent = [
            '.bi-widget{min-width:0}',
            '.bi-widget .bi-chart-host{min-width:0;overflow:hidden}',
            '.bi-widget .bi-pager{display:flex;align-items:center;justify-content:center;gap:.75rem;margin-top:.5rem;flex-wrap:wrap}',
            '.bi-widget .bi-pager-label{font-size:.8125rem;color:var(--crm-muted,#667085)}',
            '.bi-widget .bi-notice{font-size:.75rem;color:var(--crm-muted,#667085);margin-top:.35rem}',
            '.bi-widget .bi-empty{padding:1rem 0;color:var(--crm-muted,#667085);font-size:.875rem}',
            '.bi-widget .bi-error{padding:1rem 0;color:#d63939;font-size:.875rem}',
            '.bi-widget .bi-counter-grid{display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}',
            '.bi-widget .bi-counter-card{min-width:0}',
            '.bi-widget .bi-counter-value{font-size:1.75rem;line-height:1.2;font-weight:600;margin:0}',
            '.bi-widget .bi-table{width:100%;border-collapse:collapse;font-size:.875rem}',
            '.bi-widget .bi-table th,.bi-widget .bi-table td{padding:.45rem .6rem;border-bottom:1px solid var(--crm-border,#e6e7e9);text-align:left;vertical-align:top}',
            '.bi-widget .bi-table th{font-weight:600;white-space:nowrap;cursor:pointer;user-select:none}',
            '.bi-widget .bi-table tbody tr:nth-child(even){background:rgba(0,0,0,.02)}',
            '.bi-widget .bi-table-compact th,.bi-widget .bi-table-compact td{padding:.25rem .45rem;font-size:.8125rem}',
            '.bi-widget .bi-table-search{width:100%;max-width:260px;margin-bottom:.6rem}',
            '.bi-widget .bi-table-footer{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-top:.6rem;font-size:.8125rem;color:var(--crm-muted,#667085);flex-wrap:wrap}',
            '.bi-widget .bi-table-footer button{border:1px solid var(--crm-border,#e6e7e9);background:transparent;border-radius:4px;padding:.15rem .5rem;cursor:pointer}',
            '.bi-widget .bi-table-footer button:disabled{opacity:.4;cursor:default}',
            '.bi-widget .bi-funnel-steps{margin-top:.75rem;font-size:.8125rem}',
            '.bi-widget .bi-funnel-steps div{display:flex;justify-content:space-between;border-top:1px solid var(--crm-border,#e6e7e9);padding:.25rem 0}'
        ].join('\n');
        document.head.appendChild(style);
    }

    /* ------------------------------ реестр --------------------------------- */

    var MAX_POINTS = 200;

    var AXIS_WINDOW = { bar: 30, combo: 30, line: 60, heatmap: 30 };
    var FOLD_LIMIT = { pie: 12, radial: 8 };

    var FAMILIES = {
        'mini-counters': { placeholder: 'counters', colors: true, defaultType: 'cards' },
        'bar': { placeholder: 'bars', colors: true, defaultType: 'column' },
        'line': { placeholder: 'chart', colors: true, defaultType: 'area' },
        'pie': { placeholder: 'pie', colors: true, defaultType: 'pie' },
        'radial': { placeholder: 'radial', colors: true, defaultType: 'gauge' },
        'combo': { placeholder: 'combo', colors: true, defaultType: 'bar-line' },
        'table': { placeholder: 'table', colors: false, defaultType: 'plain' },
        'scatter': { placeholder: 'scatter', colors: true, defaultType: 'scatter' },
        'radar': { placeholder: 'radar', colors: true, defaultType: 'radar' },
        'heatmap': { placeholder: 'heatmap', colors: true, defaultType: 'discrete' },
        'treemap': { placeholder: 'treemap', colors: true, defaultType: 'distributed' },
        'funnel': { placeholder: 'funnel', colors: true, defaultType: 'funnel' }
    };

    // Соответствие имени типа виджета опциям рендера (как в Datavue Seeders).
    var TYPE_OPTIONS = {
        'mini-counters/cards': { layout: 'cards' },
        'mini-counters/with-progress': { layout: 'progress' },
        'bar/column': { chartType: 'bar', horizontal: false },
        'bar/bar': { chartType: 'bar', horizontal: true },
        'bar/stacked-column': { chartType: 'bar', horizontal: false, stacked: true },
        'bar/stacked-bar': { chartType: 'bar', horizontal: true, stacked: true },
        'bar/percent-stacked': { chartType: 'bar', horizontal: false, stacked: true, stackType: '100%' },
        'line/smooth': { chartType: 'line', curve: 'smooth' },
        'line/straight': { chartType: 'line', curve: 'straight' },
        'line/stepline': { chartType: 'line', curve: 'stepline' },
        'line/area': { chartType: 'area', curve: 'smooth' },
        'line/stacked-area': { chartType: 'area', curve: 'smooth', stacked: true },
        'pie/pie': { chartType: 'pie' },
        'pie/donut': { chartType: 'donut' },
        'pie/semi-donut': { chartType: 'donut', startAngle: -90, endAngle: 90 },
        'radial/gauge': { chartType: 'radialBar', multiple: false },
        'radial/multi': { chartType: 'radialBar', multiple: true },
        'combo/bar-line': { columnKind: 'column', lineKind: 'line' },
        'combo/area-line': { columnKind: 'area', lineKind: 'line' },
        'table/plain': { striped: false, compact: false },
        'table/striped': { striped: true, compact: false },
        'table/compact': { striped: false, compact: true },
        'scatter/scatter': { chartType: 'scatter' },
        'scatter/bubble': { chartType: 'bubble' },
        'radar/radar': { chartType: 'radar', filled: false },
        'radar/filled': { chartType: 'radar', filled: true },
        'radar/polar-area': { chartType: 'polarArea' },
        'heatmap/gradient': { chartType: 'heatmap', discrete: false },
        'heatmap/discrete': { chartType: 'heatmap', discrete: true },
        'treemap/treemap': { chartType: 'treemap', distributed: false },
        'treemap/distributed': { chartType: 'treemap', distributed: true },
        'funnel/funnel': { shape: 'funnel' },
        'funnel/pyramid': { shape: 'pyramid' }
    };

    var CHART_COLORS = [
        '#206bc4', '#d63939', '#2fb344', '#f76707',
        '#ae3ec9', '#4299e1', '#f59f00', '#22b8cf'
    ];

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function colorsFor(options) {
        var chosen = options && options.colors;

        if (!Array.isArray(chosen) || chosen.length === 0) return CHART_COLORS.slice();

        var result = [];

        for (var i = 0; i < Math.max(chosen.length, CHART_COLORS.length); i++) {
            result.push(typeof chosen[i] === 'string' && chosen[i].trim() !== ''
                ? chosen[i].trim()
                : CHART_COLORS[i % CHART_COLORS.length]);
        }

        return result;
    }

    function resolveThemeColors(colors) {
        if (typeof document === 'undefined') return colors;

        return colors.map(function (color) {
            if (typeof color !== 'string' || color.trim().substr(0, 4) !== 'var(') return color;

            var probe = document.createElement('span');
            probe.style.cssText = 'position:fixed;left:-9999px;visibility:hidden;';
            probe.style.color = color;
            document.body.appendChild(probe);
            var resolved = global.getComputedStyle(probe).color;
            probe.parentNode.removeChild(probe);

            return resolved || color;
        });
    }

    function looksLikeTime(labels) {
        if (!labels.length) return false;

        var matching = labels.filter(function (label) {
            return /^\d{4}($|[-/.\s]?(\d{1,2}|[QqWw]\d{1,2})\b)/.test(String(label));
        }).length;

        return matching / labels.length >= 0.8;
    }

    function axisLabels(familyName, content) {
        if (familyName === 'line') {
            return Array.isArray(content.labels) ? content.labels : [];
        }
        if (familyName === 'heatmap') {
            var first = content.series && content.series[0] && content.series[0].data;

            return Array.isArray(first) ? first.map(function (cell) { return cell && cell.x !== undefined ? cell.x : ''; }) : [];
        }

        return Array.isArray(content.categories) ? content.categories : [];
    }

    function axisWindow(familyName, content, requestedOffset) {
        var size = AXIS_WINDOW[familyName];
        if (!size || !content) return null;

        var labels = axisLabels(familyName, content);
        var total = labels.length;
        if (total <= size) return null;

        var maxStart = total - size;
        var start = clamp(
            requestedOffset === undefined || requestedOffset === null
                ? (looksLikeTime(labels) ? maxStart : 0)
                : requestedOffset,
            0,
            maxStart
        );

        return {
            total: total,
            size: size,
            start: start,
            end: start + size,
            firstLabel: String(labels[start]),
            lastLabel: String(labels[start + size - 1])
        };
    }

    function sliceByAxis(series, axis, start, end) {
        if (!Array.isArray(series) || !Array.isArray(axis)) return { series: series, axis: axis };

        return {
            series: series.map(function (item) {
                return item && Array.isArray(item.data)
                    ? Object.assign({}, item, { data: item.data.slice(start, end) })
                    : item;
            }),
            axis: axis.slice(start, end)
        };
    }

    function capFlat(series, labels) {
        if (!Array.isArray(series)) return { series: series, labels: labels };

        var limit = Math.min(series.length, MAX_POINTS);

        return {
            series: series.slice(0, limit),
            labels: Array.isArray(labels) ? labels.slice(0, limit) : labels
        };
    }

    function foldFlat(series, labels, limit, otherLabel) {
        if (!Array.isArray(series) || series.length <= limit) {
            return { series: series, labels: labels, folded: false };
        }

        var order = series.map(function (value, index) {
            return { value: Number(value) || 0, label: labels && labels[index] !== undefined ? labels[index] : '' };
        }).sort(function (a, b) { return b.value - a.value; });

        var top = order.slice(0, limit - 1);
        var rest = order.slice(limit - 1).reduce(function (sum, item) { return sum + item.value; }, 0);

        return {
            series: top.map(function (item) { return item.value; }).concat([rest]),
            labels: top.map(function (item) { return item.label; }).concat([otherLabel]),
            folded: true
        };
    }

    function capOwnData(series) {
        if (!Array.isArray(series)) return series;

        return series.map(function (item) {
            return item && Array.isArray(item.data)
                ? Object.assign({}, item, { data: item.data.slice(0, MAX_POINTS) })
                : item;
        });
    }

    function capTreemap(series) {
        if (!Array.isArray(series)) return series;

        return series.map(function (item) {
            if (!item || !Array.isArray(item.data) || item.data.length <= MAX_POINTS) return item;

            return Object.assign({}, item, {
                data: item.data.slice().sort(function (a, b) {
                    return (Number(b && b.y) || 0) - (Number(a && a.y) || 0);
                }).slice(0, MAX_POINTS)
            });
        });
    }

    function pointsIn(series) {
        return Array.isArray(series)
            ? series.reduce(function (sum, item) { return sum + (item && Array.isArray(item.data) ? item.data.length : 0); }, 0)
            : 0;
    }

    function supportsColors(name) {
        return !FAMILIES[name] || FAMILIES[name].colors !== false;
    }

    /** Порт registry.js: подготавливает пропсы, окно оси и пометку. */
    function prepare(familyName, content, options, view) {
        var data = content || {};
        options = options || {};
        view = view || {};
        var otherLabel = view.otherLabel || 'Прочее';

        function done(props, axis, notice) {
            return { props: props, axis: axis || null, notice: notice || null };
        }

        switch (familyName) {
            case 'mini-counters':
                return done({ counters: data, options: options });
            case 'table':
                return done({ table: data, options: options });
            case 'bar':
            case 'combo': {
                var axis = axisWindow(familyName, data, view.offset);
                var sliced = sliceByAxis(
                    data.series,
                    data.categories || [],
                    axis ? axis.start : 0,
                    axis ? axis.end : undefined
                );

                return done({ series: sliced.series, categories: sliced.axis, options: options }, axis);
            }
            case 'line': {
                var lineAxis = axisWindow(familyName, data, view.offset);
                var lineSliced = sliceByAxis(
                    data.series,
                    data.labels || [],
                    lineAxis ? lineAxis.start : 0,
                    lineAxis ? lineAxis.end : undefined
                );

                return done({ series: lineSliced.series, labels: lineSliced.axis, options: options }, lineAxis);
            }
            case 'pie':
            case 'radial': {
                var total = Array.isArray(data.series) ? data.series.length : 0;
                var folded = foldFlat(data.series, data.labels || [], FOLD_LIMIT[familyName], otherLabel);

                return done(
                    { series: folded.series, labels: folded.labels, options: options },
                    null,
                    folded.folded ? { kind: 'folded', shown: FOLD_LIMIT[familyName] - 1, total: total } : null
                );
            }
            case 'funnel': {
                var funnelTotal = Array.isArray(data.series) ? data.series.length : 0;
                var capped = capFlat(data.series, data.labels || []);

                return done(
                    { series: capped.series, labels: capped.labels, options: options },
                    null,
                    funnelTotal > MAX_POINTS ? { kind: 'capped', shown: MAX_POINTS, total: funnelTotal } : null
                );
            }
            case 'scatter': {
                var scatterTotal = pointsIn(data.series);
                var scatterSeries = capOwnData(data.series);
                var scatterShown = pointsIn(scatterSeries);

                return done(
                    { series: scatterSeries, options: options },
                    null,
                    scatterShown < scatterTotal ? { kind: 'capped', shown: scatterShown, total: scatterTotal } : null
                );
            }
            case 'treemap': {
                var treemapTotal = pointsIn(data.series);
                var treemapSeries = capTreemap(data.series);
                var treemapShown = pointsIn(treemapSeries);

                return done(
                    { series: treemapSeries, options: options },
                    null,
                    treemapShown < treemapTotal ? { kind: 'capped', shown: treemapShown, total: treemapTotal } : null
                );
            }
            case 'heatmap': {
                var heatAxis = axisWindow(familyName, data, view.offset);
                var heatSeries = Array.isArray(data.series)
                    ? data.series.map(function (item) {
                        return item && Array.isArray(item.data) && heatAxis
                            ? Object.assign({}, item, { data: item.data.slice(heatAxis.start, heatAxis.end) })
                            : item;
                    })
                    : data.series;

                return done({ series: heatSeries, options: options }, heatAxis);
            }
            case 'radar': {
                if (options.chartType === 'polarArea') {
                    var polarTotal = Array.isArray(data.series) ? data.series.length : 0;
                    var polar = capFlat(data.series, data.labels || data.categories || []);

                    return done(
                        { series: polar.series, categories: data.categories || [], labels: polar.labels, options: options },
                        null,
                        polarTotal > MAX_POINTS ? { kind: 'capped', shown: MAX_POINTS, total: polarTotal } : null
                    );
                }

                var categories = Array.isArray(data.categories) ? data.categories : [];
                var radarSliced = sliceByAxis(data.series, categories, 0, MAX_POINTS);

                return done(
                    { series: radarSliced.series, categories: radarSliced.axis, labels: data.labels || [], options: options },
                    null,
                    categories.length > MAX_POINTS
                        ? { kind: 'capped', shown: MAX_POINTS, total: categories.length }
                        : null
                );
            }
            default:
                return done({ options: options });
        }
    }

    function propsFor(familyName, content, options) {
        return prepare(familyName, content, options || {}).props;
    }

    function hasData(familyName, content) {
        if (!content) return false;
        if (familyName === 'mini-counters') {
            return Array.isArray(content.counters) && content.counters.length > 0;
        }
        if (familyName === 'table') {
            return Boolean(content.headers && content.rows);
        }
        return Array.isArray(content.series) && content.series.length > 0;
    }

    /* ------------------------------ рендер --------------------------------- */

    function textNode(container, text, className) {
        var el = document.createElement('div');
        el.className = className;
        el.textContent = text;
        container.appendChild(el);
        return el;
    }

    function mountChart(container, config, state) {
        if (!global.ApexCharts) {
            textNode(container, 'Не загружена библиотека ApexCharts.', 'bi-error');
            return null;
        }

        var host = document.createElement('div');
        host.className = 'bi-chart-host';
        container.appendChild(host);

        try {
            var chart = new global.ApexCharts(host, config);
            chart.render();
            state.chart = chart;
            return chart;
        } catch (error) {
            textNode(container, 'Ошибка виджета: ' + (error && error.message ? error.message : String(error)), 'bi-error');
            return null;
        }
    }

    function attachPager(container, spec, axis, state) {
        if (!axis) return;

        var pager = document.createElement('div');
        pager.className = 'bi-pager';

        var prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'btn btn-sm btn-ghost-secondary';
        prev.textContent = '◀';
        prev.disabled = axis.start === 0;
        prev.addEventListener('click', function () {
            state.offset = Math.max(0, axis.start - axis.size);
            render(container, spec);
        });

        var label = document.createElement('span');
        label.className = 'bi-pager-label';
        label.textContent = axis.firstLabel + ' … ' + axis.lastLabel
            + ' (' + (axis.start + 1) + '–' + axis.end + ' из ' + axis.total + ')';

        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'btn btn-sm btn-ghost-secondary';
        next.textContent = '▶';
        next.disabled = axis.end >= axis.total;
        next.addEventListener('click', function () {
            state.offset = Math.min(axis.total - axis.size, axis.start + axis.size);
            render(container, spec);
        });

        pager.appendChild(prev);
        pager.appendChild(label);
        pager.appendChild(next);
        container.appendChild(pager);
    }

    function attachNotice(container, notice) {
        if (!notice) return;

        var message = notice.kind === 'folded'
            ? '«Прочее» — свёрнуто мелких долей: показано ' + notice.shown + ' из ' + notice.total
            : 'Показано ' + notice.shown + ' из ' + notice.total + ' точек';

        textNode(container, message, 'bi-notice');
    }

    function baseOptions(height) {
        return {
            chart: {
                fontFamily: 'inherit',
                height: height,
                parentHeightOffset: 0,
                toolbar: { show: false },
                animations: { enabled: false }
            }
        };
    }

    function renderBar(container, props, spec, state) {
        var options = props.options;
        var horizontal = options.horizontal === true;
        var stacked = options.stacked === true;
        var height = options.height || (horizontal
            ? Math.max(240, Math.min(560, (props.categories || []).length * 28 + 80))
            : 320);

        var config = baseOptions(height);
        config.chart.type = 'bar';
        config.chart.stacked = stacked;
        config.chart.stackType = options.stackType || undefined;
        config.plotOptions = {
            bar: {
                horizontal: horizontal,
                columnWidth: '50%',
                barHeight: '70%',
                borderRadius: 2
            }
        };
        config.dataLabels = { enabled: false };
        config.series = props.series;
        config.tooltip = { theme: 'dark' };
        config.grid = {
            padding: { top: -20, right: 0, left: horizontal ? 8 : -4, bottom: -4 },
            strokeDashArray: 4
        };
        config.xaxis = {
            labels: { padding: 0 },
            tooltip: { enabled: false },
            axisBorder: { show: false },
            categories: (props.categories || []).map(String)
        };
        config.yaxis = { labels: { padding: 4 } };
        config.colors = colorsFor(options);
        config.legend = { show: props.series.length > 1, position: 'bottom' };

        mountChart(container, config, state);
    }

    function seriesMax(item) {
        var values = item && Array.isArray(item.data)
            ? item.data.map(Number).filter(function (value) { return isFinite(value); })
            : [];
        return values.length ? Math.max.apply(null, values.map(Math.abs)) : 0;
    }

    function buildYAxis(series) {
        var shared = { labels: { padding: 4 } };
        if (series.length < 2) return shared;

        var maxes = series.map(seriesMax);
        var overallMax = Math.max.apply(null, [0].concat(maxes));
        if (overallMax === 0) return shared;

        var isSecondary = maxes.map(function (max) { return max > 0 && max * 8 < overallMax; });
        if (isSecondary.indexOf(true) === -1 || isSecondary.every(Boolean)) return shared;

        var shown = { primary: false, secondary: false };

        return series.map(function (item, index) {
            var bucket = isSecondary[index] ? 'secondary' : 'primary';
            var isFirst = !shown[bucket];
            shown[bucket] = true;

            return {
                seriesName: item.name,
                opposite: isSecondary[index],
                show: isFirst,
                labels: { padding: 4 }
            };
        });
    }

    function renderLine(container, props, spec, state) {
        var options = props.options;
        var chartType = options.chartType === 'area' ? 'area' : 'line';
        var curve = options.curve || 'smooth';
        var height = options.height || 280;

        var config = baseOptions(height);
        config.chart.type = chartType;
        config.chart.stacked = options.stacked === true;
        config.dataLabels = { enabled: false };
        config.stroke = { width: 2, lineCap: 'round', curve: curve };
        config.fill = chartType === 'area'
            ? { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.05 } }
            : { opacity: 1 };
        config.series = props.series;
        config.labels = (props.labels || []).map(String);
        config.tooltip = { theme: 'dark', shared: true, intersect: false };
        config.grid = { padding: { top: -20, right: 0, left: -4, bottom: -4 }, strokeDashArray: 4 };
        config.xaxis = {
            type: 'category',
            labels: { padding: 0 },
            tooltip: { enabled: false },
            axisBorder: { show: false }
        };
        config.yaxis = buildYAxis(props.series);
        config.colors = colorsFor(options);
        config.legend = { show: props.series.length > 1, position: 'bottom' };

        mountChart(container, config, state);
    }

    function renderPie(container, props, spec, state) {
        var options = props.options;
        var series = props.series.map(function (value) {
            var number = Number(value);
            return isFinite(number) ? number : 0;
        });
        if (!series.length) return;

        var chartType = options.chartType === 'donut' ? 'donut' : 'pie';
        var isSemi = options.startAngle !== undefined && options.endAngle !== undefined;

        var config = baseOptions(options.height || 240);
        config.chart.type = chartType;
        config.chart.sparkline = { enabled: true };
        config.plotOptions = {
            pie: {
                startAngle: isSemi ? options.startAngle : 0,
                endAngle: isSemi ? options.endAngle : 360,
                offsetY: isSemi ? 40 : 0,
                donut: { size: chartType === 'donut' ? '62%' : '0%' }
            }
        };
        config.series = series;
        config.labels = (props.labels || []).map(String);
        config.colors = colorsFor(options);
        config.legend = {
            show: true,
            position: 'bottom',
            offsetY: isSemi ? -20 : 12,
            markers: { width: 10, height: 10, radius: 100 },
            itemMargin: { horizontal: 8, vertical: 8 }
        };
        config.tooltip = { theme: 'dark', fillSeriesColor: false };

        mountChart(container, config, state);
    }

    function renderRadial(container, props, spec, state) {
        var options = props.options;
        var series = props.series.map(function (value) {
            var number = Number(value);
            return isFinite(number) ? Math.max(number, 0) : 0;
        });
        if (!series.length) return;

        var multiple = options.multiple === true && series.length > 1;
        var sum = series.reduce(function (acc, value) { return acc + value; }, 0);

        var config = baseOptions(options.height || 260);
        config.chart.type = 'radialBar';
        config.plotOptions = {
            radialBar: {
                hollow: { size: multiple ? '40%' : '62%' },
                track: { strokeWidth: '90%' },
                dataLabels: {
                    name: { fontSize: '13px' },
                    value: {
                        fontSize: '20px',
                        formatter: function (value) { return Math.round(value) + '%'; }
                    },
                    total: multiple
                        ? {
                            show: true,
                            label: 'Среднее',
                            formatter: function () {
                                return Math.round(sum / series.length) + '%';
                            }
                        }
                        : { show: false }
                }
            }
        };
        config.series = series;
        config.labels = (props.labels || []).map(String);
        config.colors = colorsFor(options);
        config.legend = { show: multiple, position: 'bottom' };
        config.tooltip = { theme: 'dark' };

        mountChart(container, config, state);
    }

    function renderCombo(container, props, spec, state) {
        var options = props.options;
        var columnKind = options.columnKind === 'area' ? 'area' : 'column';

        var series = props.series.map(function (item) {
            return {
                name: item.name || '',
                type: item.kind === 'line' ? 'line' : columnKind,
                data: Array.isArray(item.data) ? item.data : []
            };
        });
        if (!series.length) return;

        var lineIndexes = series.map(function (item, index) {
            return item.type === 'line' ? index : -1;
        }).filter(function (index) { return index !== -1; });

        var config = baseOptions(options.height || 320);
        config.chart.type = 'line';
        config.plotOptions = { bar: { columnWidth: '50%', borderRadius: 2 } };
        config.stroke = {
            width: series.map(function (item) { return item.type === 'line' ? 3 : 0; }),
            curve: 'smooth'
        };
        config.fill = {
            opacity: series.map(function (item) { return item.type === 'area' ? 0.25 : 1; })
        };
        config.dataLabels = { enabled: false };
        config.series = series;
        config.yaxis = series.map(function (item, index) {
            return {
                seriesName: item.name,
                opposite: lineIndexes.indexOf(index) !== -1,
                show: index === 0 || lineIndexes.indexOf(index) !== -1,
                labels: { padding: 4 }
            };
        });
        config.xaxis = {
            categories: (props.categories || []).map(String),
            labels: { padding: 0 },
            tooltip: { enabled: false },
            axisBorder: { show: false }
        };
        config.tooltip = { theme: 'dark', shared: true, intersect: false };
        config.grid = { padding: { top: -20, right: 0, left: -4, bottom: -4 }, strokeDashArray: 4 };
        config.colors = colorsFor(options);
        config.legend = { show: true, position: 'bottom' };

        mountChart(container, config, state);
    }

    function renderScatter(container, props, spec, state) {
        var options = props.options;
        var chartType = options.chartType === 'bubble' ? 'bubble' : 'scatter';

        var config = baseOptions(options.height || 280);
        config.chart.type = chartType;
        config.chart.zoom = { enabled: false };
        config.series = props.series;
        config.fill = { opacity: chartType === 'bubble' ? 0.7 : 0.9 };
        config.markers = { size: chartType === 'bubble' ? 0 : 6 };
        config.dataLabels = { enabled: false };
        config.tooltip = { theme: 'dark' };
        config.grid = { padding: { top: -20, right: 0, left: -4, bottom: -4 }, strokeDashArray: 4 };
        config.xaxis = {
            type: 'numeric',
            tickAmount: 6,
            labels: { padding: 0 },
            tooltip: { enabled: false }
        };
        config.yaxis = { labels: { padding: 4 } };
        config.colors = colorsFor(options);
        config.legend = { show: props.series.length > 1, position: 'bottom' };

        mountChart(container, config, state);
    }

    function renderRadar(container, props, spec, state) {
        var options = props.options;

        if (options.chartType === 'polarArea') {
            var values = props.series.map(function (value) {
                var number = Number(value);
                return isFinite(number) ? number : 0;
            });
            if (!values.length) return;

            var polarConfig = baseOptions(options.height || 360);
            polarConfig.chart.type = 'polarArea';
            polarConfig.series = values;
            polarConfig.labels = ((props.labels && props.labels.length ? props.labels : props.categories) || []).map(String);
            polarConfig.stroke = { width: 1 };
            polarConfig.fill = { opacity: 0.75 };
            polarConfig.legend = { position: 'bottom' };
            polarConfig.tooltip = { theme: 'dark' };
            polarConfig.yaxis = { show: false };
            polarConfig.colors = colorsFor(options);

            mountChart(container, polarConfig, state);
            return;
        }

        var categories = (props.categories || []).map(String);
        var rawSeries = props.series.map(function (item) {
            return {
                name: item.name || '',
                data: Array.isArray(item.data) ? item.data.map(function (value) {
                    var number = Number(value);
                    return isFinite(number) ? number : 0;
                }) : []
            };
        });
        if (!categories.length || !rawSeries.length) return;

        var maxByCategory = categories.map(function (_category, index) {
            var values = rawSeries.map(function (item) {
                return item.data[index] !== undefined ? item.data[index] : 0;
            }).filter(function (value) { return isFinite(value); });
            return values.length ? Math.max.apply(null, values) : 0;
        });

        var radarSeries = rawSeries.map(function (item) {
            return {
                name: item.name,
                data: categories.map(function (_category, index) {
                    var value = item.data[index] !== undefined ? item.data[index] : 0;
                    var max = maxByCategory[index];
                    return !max || max <= 0 ? 0 : Number(((value / max) * 100).toFixed(2));
                })
            };
        });

        var config = baseOptions(options.height || 450);
        config.chart.type = 'radar';
        config.chart.width = '100%';
        config.series = radarSeries;
        config.xaxis = {
            categories: categories,
            labels: {
                show: true,
                style: { colors: categories.map(function () { return '#000000'; }), fontSize: '13px', fontWeight: 400 }
            }
        };
        config.yaxis = { min: 0, max: 100, tickAmount: 5, labels: { show: false } };
        config.colors = colorsFor(options);
        config.stroke = { width: 2 };
        config.fill = { opacity: options.filled === true ? 0.4 : 0.15 };
        config.plotOptions = {
            radar: {
                size: 110,
                offsetX: 0,
                offsetY: 0,
                polygons: {
                    strokeColors: '#d9dee4',
                    strokeWidth: 1,
                    connectorColors: '#d9dee4',
                    fill: { colors: ['#f8f9fa', '#ffffff'] }
                }
            }
        };
        config.markers = {
            size: 4,
            strokeWidth: 1,
            strokeColors: '#ffffff',
            hover: { size: 6 }
        };
        config.legend = {
            show: radarSeries.length > 1,
            position: 'bottom',
            horizontalAlign: 'center',
            offsetY: 12,
            labels: { colors: '#000000' },
            markers: { width: 10, height: 10, radius: 100 },
            itemMargin: { horizontal: 10, vertical: 8 }
        };
        config.tooltip = {
            theme: 'dark',
            y: {
                formatter: function (value, opts) {
                    var original = rawSeries[opts.seriesIndex] && rawSeries[opts.seriesIndex].data[opts.dataPointIndex];
                    return original !== undefined && isFinite(Number(original))
                        ? Number(original).toLocaleString('ru-RU', { maximumFractionDigits: 2 })
                        : value;
                }
            }
        };
        config.responsive = [{
            breakpoint: 768,
            options: {
                chart: { height: 350 },
                plotOptions: { radar: { size: 110 } },
                xaxis: { labels: { style: { fontSize: '11px' } } },
                legend: { offsetY: 5 }
            }
        }];

        mountChart(container, config, state);
    }

    function rowMinMax(row) {
        var values = ((row && row.data) || []).map(function (cell) {
            return Number(cell && cell.y !== undefined ? cell.y : 0);
        }).filter(function (value) { return isFinite(value); });

        if (!values.length) return { min: 0, max: 0 };
        return { min: Math.min.apply(null, values), max: Math.max.apply(null, values) };
    }

    function normalizeHeatmap(series) {
        return series.map(function (row) {
            var bounds = rowMinMax(row);
            var span = bounds.max - bounds.min;

            return {
                name: row.name,
                data: ((row && row.data) || []).map(function (cell) {
                    var realValue = Number(cell && cell.y !== undefined ? cell.y : 0);
                    var relative = span > 0 ? ((realValue - bounds.min) / span) * 100 : 50;

                    return {
                        x: cell && cell.x !== undefined ? cell.x : '',
                        y: Math.round(relative * 100) / 100,
                        realValue: realValue
                    };
                })
            };
        });
    }

    function renderHeatmap(container, props, spec, state) {
        var options = props.options;
        if (!props.series.length) return;

        var discrete = options.discrete === true;
        var series = normalizeHeatmap(props.series);
        var colors = resolveThemeColors(colorsFor(options));

        var defaultRange = ['#2fb344', '#f59f00', '#f76707', '#d63939'];
        var chosen = Array.isArray(options.colors) ? options.colors : [];
        var rangeColors = resolveThemeColors(defaultRange.map(function (fallback, index) {
            return typeof chosen[index] === 'string' && chosen[index].trim() !== '' ? chosen[index].trim() : fallback;
        }));
        var step = 100 / rangeColors.length;
        var ranges = discrete ? rangeColors.map(function (color, index) {
            var from = step * index;
            var to = index === rangeColors.length - 1 ? 100 : step * (index + 1);
            return { from: from, to: to, color: color, name: Math.round(from) + '–' + Math.round(to) + '%' };
        }) : undefined;

        var config = baseOptions(options.height || Math.max(240, Math.min(520, props.series.length * 32 + 80)));
        config.chart.type = 'heatmap';
        config.plotOptions = {
            heatmap: {
                shadeIntensity: 0.6,
                radius: 2,
                useFillColorAsStroke: false,
                colorScale: ranges ? { ranges: ranges } : {}
            }
        };
        config.dataLabels = { enabled: false };
        config.series = series;
        config.tooltip = {
            theme: 'dark',
            y: {
                formatter: function (_value, opts) {
                    var w = opts && opts.w;
                    var point = w && w.config && w.config.series[opts.seriesIndex]
                        && w.config.series[opts.seriesIndex].data[opts.dataPointIndex];
                    return point && point.realValue !== undefined ? point.realValue : _value;
                }
            }
        };
        config.stroke = { width: 1 };
        config.colors = colors;
        config.xaxis = {
            type: 'category',
            labels: { rotate: -45, trim: true },
            tooltip: { enabled: false }
        };
        config.legend = { show: discrete, position: 'bottom' };

        mountChart(container, config, state);
    }

    function renderTreemap(container, props, spec, state) {
        var options = props.options;
        var distributed = options.distributed === true;

        var config = baseOptions(options.height || 320);
        config.chart.type = 'treemap';
        config.plotOptions = {
            treemap: {
                distributed: distributed,
                enableShades: !distributed,
                shadeIntensity: 0.4
            }
        };
        config.dataLabels = { enabled: true, style: { fontSize: '12px' } };
        config.series = props.series;
        config.tooltip = { theme: 'dark' };
        config.legend = { show: false };
        config.colors = resolveThemeColors(colorsFor(options));

        mountChart(container, config, state);
    }

    function renderFunnel(container, props, spec, state) {
        var options = props.options;
        var isPyramid = options.shape === 'pyramid';
        var values = props.series.map(function (value) { return Number(value) || 0; });
        var labels = (props.labels || []).map(String);

        var data = isPyramid ? values.slice().reverse() : values;
        var categories = isPyramid ? labels.slice().reverse() : labels;
        var first = values[0] || 0;

        var config = baseOptions(options.height || Math.max(220, data.length * 48 + 40));
        config.chart.type = 'bar';
        config.plotOptions = {
            bar: {
                horizontal: true,
                distributed: true,
                barHeight: '78%',
                isFunnel: true
            }
        };
        config.series = [{ name: 'Этап', data: data }];
        config.xaxis = { categories: categories };
        config.dataLabels = {
            enabled: true,
            formatter: function (value, opts) {
                return (opts.w.globals.labels[opts.dataPointIndex] || '') + ': ' + value;
            },
            dropShadow: { enabled: false }
        };
        config.colors = colorsFor(options);
        config.tooltip = { theme: 'dark' };
        config.legend = { show: false };

        mountChart(container, config, state);

        if (values.length > 1) {
            var steps = document.createElement('div');
            steps.className = 'bi-funnel-steps';

            for (var i = 1; i < values.length; i++) {
                var fromPrev = values[i - 1]
                    ? Math.round((values[i] / values[i - 1]) * 1000) / 10
                    : 100;
                var fromFirst = first ? Math.round((values[i] / first) * 1000) / 10 : 0;
                var row = document.createElement('div');
                row.innerHTML = '<span></span><span><strong>' + fromPrev + '%</strong> от предыдущего · '
                    + fromFirst + '% от первого</span>';
                row.firstChild.textContent = labels[i] || '';
                steps.appendChild(row);
            }

            container.appendChild(steps);
        }
    }

    function formatCell(value) {
        if (value === null || value === undefined) return '';
        if (typeof value === 'number') return value.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
        return String(value);
    }

    function renderTable(container, props, spec, state) {
        var table = props.table || { headers: [], rows: [] };
        var headers = Array.isArray(table.headers) ? table.headers : [];
        var rows = Array.isArray(table.rows) ? table.rows.slice() : [];
        var options = props.options;

        var search = document.createElement('input');
        search.type = 'search';
        search.className = 'form-control form-control-sm bi-table-search';
        search.placeholder = 'Поиск';
        search.setAttribute('aria-label', 'Поиск по таблице');
        container.appendChild(search);

        var wrap = document.createElement('div');
        wrap.className = 'table-responsive';
        var tableEl = document.createElement('table');
        tableEl.className = 'bi-table table table-vcenter card-table'
            + (options.striped ? ' table-striped' : '')
            + (options.compact ? ' bi-table-compact table-sm' : '');
        wrap.appendChild(tableEl);
        container.appendChild(wrap);

        var footer = document.createElement('div');
        footer.className = 'bi-table-footer';
        container.appendChild(footer);

        var stateTable = state.table || (state.table = { page: 1, query: '', sortColumn: null, sortOrder: 'asc' });
        var perPage = options.compact ? 12 : 5;

        function draw() {
            var visible = rows.filter(function (row) {
                if (!stateTable.query) return true;
                return row.some(function (cell) {
                    return String(cell === null || cell === undefined ? '' : cell)
                        .toLowerCase().indexOf(stateTable.query) !== -1;
                });
            });

            if (stateTable.sortColumn !== null) {
                var index = stateTable.sortColumn;
                var direction = stateTable.sortOrder === 'asc' ? 1 : -1;

                visible.sort(function (a, b) {
                    var va = a[index];
                    var vb = b[index];

                    if (va === null || va === undefined) return 1;
                    if (vb === null || vb === undefined) return -1;

                    if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * direction;

                    return String(va).localeCompare(String(vb), 'ru') * direction;
                });
            }

            var totalPages = Math.max(1, Math.ceil(visible.length / perPage));
            stateTable.page = clamp(stateTable.page, 1, totalPages);
            var start = (stateTable.page - 1) * perPage;
            var pageRows = visible.slice(start, start + perPage);

            var thead = document.createElement('thead');
            var headRow = document.createElement('tr');

            headers.forEach(function (header, index) {
                var th = document.createElement('th');
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'table-sort'
                    + (stateTable.sortColumn === index
                        ? (stateTable.sortOrder === 'asc' ? ' asc' : ' desc')
                        : '');
                button.textContent = String(header);
                button.addEventListener('click', function () {
                    if (stateTable.sortColumn === index) {
                        stateTable.sortOrder = stateTable.sortOrder === 'asc' ? 'desc' : 'asc';
                    } else {
                        stateTable.sortColumn = index;
                        stateTable.sortOrder = 'asc';
                    }
                    draw();
                });
                th.appendChild(button);
                headRow.appendChild(th);
            });

            thead.appendChild(headRow);

            var tbody = document.createElement('tbody');
            tbody.className = 'table-tbody';

            if (!pageRows.length) {
                var emptyRow = document.createElement('tr');
                var emptyCell = document.createElement('td');
                emptyCell.colSpan = headers.length || 1;
                emptyCell.className = 'text-center text-secondary py-4';
                emptyCell.textContent = 'Нет данных';
                emptyRow.appendChild(emptyCell);
                tbody.appendChild(emptyRow);
            } else {
                pageRows.forEach(function (row) {
                    var tr = document.createElement('tr');
                    headers.forEach(function (_header, index) {
                        var td = document.createElement('td');
                        td.className = 'format-number';
                        td.textContent = formatCell(row[index]);
                        tr.appendChild(td);
                    });
                    tbody.appendChild(tr);
                });
            }

            tableEl.textContent = '';
            tableEl.appendChild(thead);
            tableEl.appendChild(tbody);

            footer.textContent = '';
            var info = document.createElement('span');
            info.textContent = 'Показано ' + (visible.length ? start + 1 : 0) + '–' + Math.min(start + perPage, visible.length)
                + ' из ' + visible.length;
            footer.appendChild(info);

            var controls = document.createElement('span');
            var prev = document.createElement('button');
            prev.type = 'button';
            prev.textContent = '◀';
            prev.disabled = stateTable.page <= 1;
            prev.addEventListener('click', function () { stateTable.page--; draw(); });

            var next = document.createElement('button');
            next.type = 'button';
            next.textContent = '▶';
            next.disabled = stateTable.page >= totalPages;
            next.addEventListener('click', function () { stateTable.page++; draw(); });

            var counter = document.createElement('span');
            counter.textContent = ' ' + stateTable.page + ' / ' + totalPages + ' ';
            controls.appendChild(prev);
            controls.appendChild(counter);
            controls.appendChild(next);
            footer.appendChild(controls);
        }

        search.value = stateTable.query;
        search.addEventListener('input', function () {
            stateTable.query = search.value.toLowerCase().trim();
            stateTable.page = 1;
            draw();
        });

        draw();
    }

    function renderMiniCounters(container, props, spec, state) {
        var counters = (props.counters && props.counters.counters) || [];
        var options = props.options;
        var progress = options.layout === 'progress';
        var count = counters.length;

        var columnClass = count <= 1
            ? 'col-12'
            : (count === 2 ? 'col-12 col-md-6' : (count === 3 ? 'col-12 col-md-4' : 'col-12 col-sm-6 col-xl-3'));

        var grid = document.createElement('div');
        grid.className = progress ? 'row g-2' : 'bi-counter-grid';
        container.appendChild(grid);

        counters.forEach(function (counter, index) {
            var cell = document.createElement('div');
            cell.className = progress ? columnClass : '';
            var card = document.createElement('div');
            card.className = 'card bi-counter-card';
            var body = document.createElement('div');
            body.className = 'card-body';
            card.appendChild(body);
            cell.appendChild(card);
            grid.appendChild(cell);

            var ownColors = Array.isArray(options.colors) ? options.colors : [];
            var ownColor = typeof ownColors[index] === 'string' && ownColors[index].trim() !== ''
                ? ownColors[index].trim()
                : null;

            var name = document.createElement('div');
            name.className = 'subheader mb-1';
            name.textContent = counter.name || '';
            body.appendChild(name);

            var percent = progress
                ? Math.max(0, Math.min(100, Number(counter.percent) || 0))
                : null;

            var row = document.createElement('div');
            row.className = progress
                ? 'd-flex align-items-baseline justify-content-between'
                : '';
            var value = document.createElement('div');
            value.className = 'bi-counter-value';
            value.textContent = (counter.prefix || '') + (counter.value || 0) + (counter.suffix || '');
            if (ownColor) value.style.color = ownColor;
            row.appendChild(value);

            if (progress) {
                var label = document.createElement('div');
                label.className = 'text-secondary small';
                label.textContent = percent + '%';
                row.appendChild(label);
            }

            body.appendChild(row);

            if (progress) {
                var bar = document.createElement('div');
                bar.className = 'progress progress-sm mt-2';
                var inner = document.createElement('div');
                inner.className = 'progress-bar' + (ownColor ? '' : (percent >= 100 ? ' bg-success' : ' bg-primary'));
                inner.style.width = percent + '%';
                if (ownColor) inner.style.backgroundColor = ownColor;
                bar.appendChild(inner);
                body.appendChild(bar);
            }
        });
    }

    var RENDERERS = {
        'mini-counters': renderMiniCounters,
        'bar': renderBar,
        'line': renderLine,
        'pie': renderPie,
        'radial': renderRadial,
        'combo': renderCombo,
        'table': renderTable,
        'scatter': renderScatter,
        'radar': renderRadar,
        'heatmap': renderHeatmap,
        'treemap': renderTreemap,
        'funnel': renderFunnel
    };

    /**
     * Основной вход: render(container, { family, type, content, options, view }).
     * Возвращает { destroy() }.
     */
    function render(container, spec) {
        if (!container) return { destroy: function () {} };

        spec = spec || {};

        var state = container.__biView || (container.__biView = { offset: null, chart: null });

        if (state.chart) {
            try { state.chart.destroy(); } catch (error) { /* график уже мёртв */ }
            state.chart = null;
        }

        container.textContent = '';

        var family = spec.family;

        if (!FAMILIES[family]) {
            textNode(container, 'Семейство не поддерживается', 'bi-error');
            return { destroy: function () {} };
        }

        var type = spec.type || FAMILIES[family].defaultType;
        var options = Object.assign(
            {},
            TYPE_OPTIONS[family + '/' + type] || {},
            spec.options || {}
        );
        var view = Object.assign({}, spec.view || {}, {
            offset: spec.view && spec.view.offset !== undefined ? spec.view.offset : state.offset
        });

        if (!hasData(family, spec.content)) {
            textNode(container, 'Нет данных', 'bi-empty');
            return { destroy: function () {} };
        }

        var prepared = prepare(family, spec.content, options, view);
        var renderer = RENDERERS[family];

        if (!renderer) {
            textNode(container, 'Семейство не поддерживается', 'bi-error');
            return { destroy: function () {} };
        }

        try {
            renderer(container, prepared.props, spec, state);
            attachNotice(container, prepared.notice);
            attachPager(container, spec, prepared.axis, state);
        } catch (error) {
            container.textContent = '';
            textNode(container, 'Ошибка виджета: ' + (error && error.message ? error.message : String(error)), 'bi-error');
        }

        return {
            destroy: function () {
                if (state.chart) {
                    try { state.chart.destroy(); } catch (error) { /* noop */ }
                    state.chart = null;
                }
            }
        };
    }

    global.BiWidgets = {
        FAMILIES: FAMILIES,
        prepare: prepare,
        propsFor: propsFor,
        hasData: hasData,
        supportsColors: supportsColors,
        render: render
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = global.BiWidgets;
    }
})(typeof window !== 'undefined' ? window : this);
