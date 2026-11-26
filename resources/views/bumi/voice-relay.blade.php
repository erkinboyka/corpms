<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Bumi: микрофон') }}</title>
    <style>
        html, body { margin: 0; height: 100%; font: 13px/1.4 system-ui, sans-serif; background: #0d3831; color: #d7fff0; }
        .relay-status { position: fixed; inset: 0; display: grid; place-items: center; padding: 12px; text-align: center; }
    </style>
</head>
<body data-relay-parent="{{ $parentOrigin }}" data-relay-nonce="{{ $nonce }}">
<div class="relay-status" role="status">{{ __('Готовлю микрофон для Bumi…') }}</div>
<script src="/assets/js/bumi-voice-relay.js?v1" defer></script>
</body>
</html>
