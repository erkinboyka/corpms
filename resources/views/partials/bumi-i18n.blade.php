@php
    use App\Support\BumiCopy;

    $bumiLineMap = [];
    foreach (BumiCopy::LINES as $bumiLine) {
        $bumiLineMap[$bumiLine] = __($bumiLine);
    }

    $bumiMoodMap = [];
    foreach (BumiCopy::MOODS as $bumiMood => $bumiMoodLine) {
        $bumiMoodMap[$bumiMood] = __($bumiMoodLine);
    }

    $bumiUiMap = [];
    foreach (BumiCopy::UI as $bumiUiKey => $bumiUiLine) {
        $bumiUiMap[$bumiUiKey] = __($bumiUiLine);
    }
@endphp
<script type="application/json" id="bumi-i18n">{!! json_encode([
    'lines' => $bumiLineMap,
    'moods' => $bumiMoodMap,
    'ui' => $bumiUiMap,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
