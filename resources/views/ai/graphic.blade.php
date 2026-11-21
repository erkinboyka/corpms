@php
    $count = count($series);
    $values = array_column($series, 'value');
    $low = min(0, min($values));
    $high = max(0, max($values));
    $span = ($high - $low) ?: 1;
    $magnitude = max(abs($low), $high) ?: 1;
    $sum = array_sum($values);
    if (in_array($style, ['pie','donut','stack'], true) && ($low < 0 || $sum <= 0)) $style = 'bars';
    $cartesian = in_array($style, ['line','area','step','columns','stems'], true);
    $tiles = in_array($style, ['tiles','heat','bubbles'], true);
    $shares = in_array($style, ['pie','donut','stack'], true);
    $height = $cartesian ? 348 : ($tiles ? 90 + ceil($count/3)*106 : ($shares ? max(330, 132+34*$count) : 86+52*$count));
    $colors = ['#91e0cc','#f9c785','#a6baff','#ec9eb5','#b3d584','#d3b4f6','#7fc7ed'];
    $yAt = fn ($v) => round(254 - 157*($v-$low)/$span, 2);
    $xAt = fn ($v) => round(40 + 752*($v-$low)/$span, 2);
    $baseY = $yAt(0);
    $baseX = $xAt(0);
    $short = fn ($label, $limit) => \Illuminate\Support\Str::limit((string) $label, $limit);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" width="840" height="{{ $height }}" viewBox="0 0 840 {{ $height }}" role="img" aria-label="{{ $title }}">
<title>{{ $title }}</title>
@if(isset($source))<desc>{{ implode(', ', array_column($source['items'], 'label')) }} · {{ $source['context'] }}</desc>@endif
<rect width="840" height="{{ $height }}" rx="16" fill="#162d31"/>
<g fill="#f1f5f3" font-family="Arial,sans-serif">
<text x="32" y="43" font-size="24" font-weight="bold">{{ $short($title, 54) }}</text>
@if($cartesian)
    @php
        $points = [];
        $step = 720/$count;
        foreach ($series as $i => $item) $points[] = [round(76+($i+.5)*$step,2), $yAt($item['value'])];
        $path = 'M'.implode(' ', $points[0]);
        foreach (array_slice($points, 1) as [$x, $y]) $path .= $style === 'step' ? " H$x V$y" : " L$x $y";
    @endphp
    @foreach(array_unique([$high, 0, $low]) as $tick)
    <path d="M76 {{ $yAt($tick) }} H800" stroke="#50666b" stroke-dasharray="4 4"/><text x="68" y="{{ $yAt($tick)+4 }}" text-anchor="end" font-size="13">{{ $tick }}</text>
    @endforeach
    @if($style === 'area')<path d="{{ $path }} V{{ $baseY }} H{{ $points[0][0] }} Z" fill="#508b86" />@endif
    @if(in_array($style, ['line','step','area']))<path d="{{ $path }}" stroke="#91e0cc" stroke-width="4" fill="none"/>@endif
    @foreach($points as $i => [$x,$y])
    <g><title>{{ $series[$i]['label'] }} · {{ $series[$i]['value'] }}</title>
    @if($style === 'columns')
        <rect x="{{ $x-min(74,$step*.66)/2 }}" y="{{ min($baseY,$y) }}" width="{{ min(74,$step*.66) }}" height="{{ max(1,abs($baseY-$y)) }}" fill="{{ $colors[$i%7] }}"/>
    @else
        @if($style === 'stems')<path d="M{{ $x }} {{ $baseY }} V{{ $y }}" stroke="{{ $colors[$i%7] }}" stroke-width="3"/>@endif
        <circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $count>40 ? 2 : 5 }}" fill="#91e0cc"/>
    @endif
    @if($count <= 12)<text x="{{ $x }}" y="{{ $y-10 }}" text-anchor="middle" font-size="14">{{ $series[$i]['value'] }}</text>@endif
    @if($i % max(1, (int)ceil($count/6)) === 0 || $i === $count-1)
    <text x="{{ $x }}" y="{{ $count>6 && $i===$count-1 ? 318 : 296 }}" text-anchor="middle" font-size="14">{{ $short($series[$i]['label'], 12) }}</text>
    @endif
    </g>
    @endforeach
@elseif($shares)
    @php $offset = 0; @endphp
    @foreach($series as $i=>$item)
    @php $pct = $item['value']*100/$sum; @endphp
    @php $color = $colors[$i%7]; @endphp
    @if($style === 'stack')
        <rect x="{{ 32+$offset*7.76 }}" y="61" height="24" width="{{ $pct*7.76 }}" fill="{{ $color }}"/>
    @elseif($pct > 0)
        <circle cx="165" cy="184" r="{{ $style==='pie'?47:78 }}" fill="none" stroke="{{ $color }}" stroke-width="{{ $style==='pie'?94:26 }}" pathLength="100" stroke-dasharray="{{ $pct }} {{ 100-$pct }}" stroke-dashoffset="{{ -$offset }}" transform="rotate(-90 165 184)"/>
    @endif
    @php $offset += $pct; @endphp
    @php $left = $style==='stack'?40:306; @endphp
    @php $y = 118+34*$i; @endphp
    <circle cx="{{ $left }}" cy="{{ $y-5 }}" r="6" fill="{{ $color }}"/>
    <text x="{{ $left+18 }}" y="{{ $y }}" font-size="16">{{ $short($item['label'],32) }}</text>
    <text x="800" y="{{ $y }}" text-anchor="end" font-size="16">{{ $item['value'] }} · {{ round($pct,1) }}%</text>
    @endforeach
    @if($style === 'donut')<text x="165" y="193" text-anchor="middle" font-size="26">{{ $sum }}</text>@endif
@elseif($tiles)
    @foreach($series as $i=>$item)
    @php
        $x=32+($i%3)*260;
        $y=72+intdiv($i,3)*106;
        $fraction=abs($item['value'])/$magnitude;
    @endphp
    <g><title>{{ $item['label'] }} · {{ $item['value'] }}</title>
    <rect x="{{ $x }}" y="{{ $y }}" width="249" height="94" rx="8" fill="#263f43"/>
    @if($style === 'heat')
    <rect x="{{ $x }}" y="{{ $y }}" width="249" height="94" rx="8" fill="{{ $item['value']<0?'#e6a489':'#91e0cc' }}" opacity="{{ .08+.32*$fraction }}"/>
    @elseif($style === 'bubbles')
    <circle cx="{{ $x+208 }}" cy="{{ $y+48 }}" r="{{ 32*sqrt($fraction) }}" fill="{{ $colors[$i%7] }}"/>
    @else
    <rect x="{{ $x }}" y="{{ $y+9 }}" width="4" height="76" fill="{{ $colors[$i%7] }}"/>
    @endif
    <text x="{{ $x+14 }}" y="{{ $y+32 }}" font-size="15">{{ $short($item['label'], $style==='bubbles'?19:25) }}</text>
    <text x="{{ $x+14 }}" y="{{ $y+72 }}" font-size="27" font-weight="bold">{{ $item['value'] }}</text></g>
    @endforeach
@else
    @php $ordered = $style === 'rank' ? collect($series)->sortByDesc('value')->values()->all() : $series; @endphp
    @foreach($ordered as $i=>$item)
    @php $y=83+52*$i; @endphp
    @php $x=$xAt($item['value']); @endphp
    <g><title>{{ $item['label'] }} · {{ $item['value'] }}</title>
    <text x="40" y="{{ $y }}" font-size="16">{{ $style==='rank' ? ($i+1).'. ' : '' }}{{ $short($item['label'],54) }}</text>
    <text x="796" y="{{ $y }}" text-anchor="end" font-size="17" font-weight="bold">{{ $item['value'] }}</text>
    <path d="M40 {{ $y+14 }} H792" stroke="#435b61" stroke-width="{{ $style==='dots'?2:6 }}"/>
    @if($style!=='dots')<path d="M{{ $baseX }} {{ $y+14 }} H{{ $x }}" stroke="{{ $colors[$i%7] }}" stroke-width="{{ $style==='lollipop'?3:8 }}"/>@endif
    @if(in_array($style,['dots','lollipop']))<circle cx="{{ $x }}" cy="{{ $y+14 }}" r="7" fill="{{ $colors[$i%7] }}"/>@endif
    @if($low<0)<path d="M{{ $baseX }} {{ $y+7 }} v14" stroke="#e9eced"/>@endif
    </g>
    @endforeach
@endif
</g></svg>
