@extends('layouts.tabler')
@section('title', __('Резервные копии'))
@section('content')
<div class="container-xl py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">{{ __('Резервные копии') }}</h2>
    <div class="d-flex gap-2">
      <form method="post" action="{{ route('backups.db') }}">@csrf<button class="btn btn-primary" type="submit">{{ __('Создать копию БД') }}</button></form>
      <form method="post" action="{{ route('backups.files') }}">@csrf<button class="btn btn-outline-primary" type="submit">{{ __('Создать копию файлов') }}</button></form>
    </div>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-vcenter">
        <thead><tr><th>{{ __('Файл') }}</th><th>{{ __('Размер') }}</th><th>{{ __('Дата') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($files as $f)
          <tr>
            <td>{{ $f['name'] }}</td>
            <td>{{ number_format($f['size']/1024,1,',',' ') }} {{ __('КБ') }}</td>
            <td>{{ \Carbon\Carbon::createFromTimestamp($f['mtime'])->format('d.m.Y H:i:s') }}</td>
            <td><a class="btn btn-sm btn-outline-secondary" href="{{ route('backups.download', $f['name']) }}">{{ __('Скачать') }}</a></td>
          </tr>
        @empty
          <tr><td colspan="4" class="text-center text-secondary">{{ __('Копий пока нет') }}</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
