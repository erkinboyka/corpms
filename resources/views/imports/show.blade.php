@extends('layouts.tabler')
@section('title', __('Задание импорта'))
@section('content')
<div class="container-xl py-4">
  <h2 class="mb-3">{{ __('Задание #') }}{{ $job->id }} ({{ $job->type }})</h2>
  <p>{{ __('Статус') }} <strong>{{ $job->status }}</strong> {{ __('| Импортировано') }} {{ $job->imported_rows }}/{{ $job->total_rows }} {{ __('| Ошибок') }} {{ $job->failed_rows }}</p>
  @if ($job->status === 'pending')
    <form method="post" action="{{ route('imports.run', $job) }}" class="mb-3">@csrf<button class="btn btn-primary" type="submit">{{ __('Запустить импорт') }}</button></form>
  @endif
  <div class="card mb-3">
    <div class="card-header"><h4 class="card-title">{{ __('Превью (первые 5 строк)') }}</h4></div>
    <div class="table-responsive">
      <table class="table table-sm">
        <thead><tr>@foreach($header as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($preview as $r)
          <tr>@foreach($r as $c)<td>{{ $c }}</td>@endforeach</tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>
  @if (!empty($job->errors))
    <div class="card">
      <div class="card-header"><h4 class="card-title">{{ __('Ошибки') }}</h4></div>
      <div class="card-body">
        <ul class="mb-0">
        @foreach ($job->errors as $e)<li>{{ $e }}</li>@endforeach
        </ul>
      </div>
    </div>
  @endif
</div>
@endsection