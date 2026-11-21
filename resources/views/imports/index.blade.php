@extends('layouts.tabler')
@section('title', __('Импорт данных'))
@section('content')
<div class="container-xl py-4">
  <h2 class="mb-3">{{ __('Импорт данных (переезд)') }}</h2>
  <div class="card mb-4">
    <div class="card-body">
      <form method="post" action="{{ route('imports.upload') }}" enctype="multipart/form-data" class="row g-3">
        @csrf
        <div class="col-md-4">
          <label class="form-label">{{ __('Тип данных') }}</label>
          <select name="type" class="form-select">
            <option value="employees">{{ __('Сотрудники') }}</option>
            <option value="departments">{{ __('Отделы') }}</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">{{ __('CSV файл') }}</label>
          <input type="file" name="file" class="form-control" accept=".csv,.txt" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-primary w-100" type="submit">{{ __('Загрузить') }}</button>
        </div>
      </form>
      <div class="text-secondary small mt-2">{{ __('CSV с заголовком. Поля: employees — last_name,first_name,middle_name,email,phone,department_code,position_code,employee_no,hired_at; departments — code,name,parent_code') }}</div>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-vcenter">
        <thead><tr><th>ID</th><th>{{ __('Тип') }}</th><th>{{ __('Статус') }}</th><th>{{ __('Импортировано') }}</th><th>{{ __('Ошибки') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($jobs as $j)
          <tr>
            <td>{{ $j->id }}</td>
            <td>{{ $j->type }}</td>
            <td>{{ $j->status }}</td>
            <td>{{ $j->imported_rows }}/{{ $j->total_rows }}</td>
            <td>{{ $j->failed_rows }}</td>
            <td><a class="btn btn-sm btn-outline-secondary" href="{{ route('imports.show', $j) }}">{{ __('Открыть') }}</a></td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-secondary">{{ __('Нет заданий') }}</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection