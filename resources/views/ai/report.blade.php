<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }} · {{ $edition['name'] }}</title>
<style>
*{box-sizing:border-box}body{margin:0;background:var(--paper);font:16px/1.5 system-ui,sans-serif;color:#192a32}main{max-width:960px;margin:0 auto;background:white;min-height:100vh;padding:clamp(20px,6vw,76px)}header{border-top:10px solid var(--ink);padding:1.3em 0;border-bottom:1px solid #c6d0d1}header p,footer{color:var(--ink)}h1{font: bold clamp(32px,6vw,60px)/1.06 Georgia,serif;margin:.35em 0}h2{font-size:1.12rem;color:var(--ink);margin:0 0 .8rem}section{margin:2em 0}svg{width:100%;height:auto;display:block}table{border-collapse:collapse;width:100%}th,td{padding:.6em;border-bottom:1px solid #d8dfe1;text-align:left}td:last-child,th:last-child{text-align:right;font-variant-numeric:tabular-nums}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(170px,100%),1fr));gap:12px}.card,.focus{background:var(--paper);padding:1.2em;border-radius:8px;overflow-wrap:anywhere}.card b,.focus b{display:block;font-size:2rem;color:var(--ink)}.focus{border-left:7px solid var(--ink)}.focus b{font: bold 3.7rem/1.2 Georgia}.rank{display:grid;grid-template-columns:2em 1fr auto;gap:.5em;align-items:baseline;padding:.6em 0;border-bottom:1px solid #d8dfe1}.rank i{color:var(--ink)}a{color:var(--ink)}footer{border-top:2px solid var(--ink);padding-top:1em;font-size:.88rem}footer a,footer span{display:inline-block;margin-right:1em}@media print{main{padding:0}body{background:white}.card,.focus,tr{break-inside:avoid}h2{break-after:avoid}}
</style></head>
<body style="--ink:{{ $edition['ink'] }};--paper:{{ $edition['paper'] }}"><main>
<header><p>BM Systems · {{ $edition['name'] }}</p><h1>{{ $title }}</h1><small>{{ $source['context'] }}</small></header>
@foreach($edition['sections'] as $section)
<section>
@switch($section)
@case('focus')
<div class="focus"><h2>{{ __('Первый показатель выборки') }}</h2><b>{{ $rows[0]['value'] }}</b>{{ $rows[0]['label'] }}</div>
@break
@case('extremes')
<h2>{{ __('Границы значений') }}</h2><div class="grid">
@foreach([__('Максимум') => $ranked[0], __('Минимум') => $ranked[count($ranked)-1]] as $caption => $row)
<div class="card"><small>{{ $caption }}</small><b>{{ $row['value'] }}</b>{{ $row['label'] }}</div>
@endforeach
</div>
@break
@case('rank')
<h2>{{ __('По убыванию значения') }}</h2>
@foreach($ranked as $row)<div class="rank"><i>{{ $loop->iteration }}</i><span>{{ $row['label'] }}</span><strong>{{ $row['value'] }}</strong></div>@endforeach
@break
@case('cards')
<h2>{{ __('Показатели') }}</h2><div class="grid">@foreach($rows as $row)<div class="card"><b>{{ $row['value'] }}</b>{{ $row['label'] }}</div>@endforeach</div>
@break
@case('chart')
{!! $graphic !!}
@break
@case('table')
<h2>{{ __('Исходные значения') }}</h2><table><thead><tr><th>{{ __('Показатель') }}</th><th>{{ __('Значение') }}</th></tr></thead><tbody>
@foreach($rows as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['value'] }}</td></tr>@endforeach
</tbody></table>
@break
@endswitch
</section>
@endforeach
<footer><strong>{{ __('Источники') }}</strong><p>@foreach($source['items'] as $item)@if($item['url'])<a href="{{ url($item['url']) }}">{{ $item['label'] }}</a>@else<span>{{ $item['label'] }}</span>@endif @endforeach</p>{{ $source['context'] }}</footer>
</main></body></html>
