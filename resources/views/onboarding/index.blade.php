@extends('layouts.tabler')
@section('title', __('Первый запуск'))
@section('content')
<div class="container-xl py-4">
  <div class="row justify-content-center">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Добро пожаловать в BM Systems') }}</h3></div>
        <div class="card-body">
          <p class="text-secondary">{{ __('Завершите базовую настройку компании, чтобы начать работу') }}</p>
          <form method="post" action="{{ route('onboarding.store') }}">
            @csrf
            <div class="mb-3">
              <label class="form-label" for="onb-name">{{ __('Название компании') }}</label>
              <input type="text" id="onb-name" name="name" class="form-control" value="{{ old('name', $company->name ?? '') }}" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="onb-legal-name">{{ __('Юридическое название') }}</label>
              <input type="text" id="onb-legal-name" name="legal_name" class="form-control" value="{{ old('legal_name', $company->legal_name ?? '') }}">
            </div>
            <div class="mb-3">
              <label class="form-label" for="onb-address">{{ __('Адрес') }}</label>
              <input type="text" id="onb-address" name="address" class="form-control" value="{{ old('address', $company->address ?? '') }}">
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label" for="onb-phone">{{ __('Телефон') }}</label>
                <input type="text" id="onb-phone" name="phone" class="form-control" value="{{ old('phone', $company->phone ?? '') }}">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label" for="onb-email">Email</label>
                <input type="email" id="onb-email" name="email" class="form-control" value="{{ old('email', $company->email ?? '') }}">
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="onb-language">{{ __('Язык интерфейса по умолчанию') }}</label>
              <select id="onb-language" name="language" class="form-select">
                <option value="ru" @selected(old('language','ru')==='ru')>{{ __('Русский') }}</option>
                <option value="en" @selected(old('language')==='en')>English</option>
                <option value="tg" @selected(old('language')==='tg')>{{ __('Тоҷикӣ') }}</option>
                <option value="uz" @selected(old('language')==='uz')>O'zbek</option>
              </select>
            </div>
            <button class="btn btn-primary" type="submit">{{ __('Завершить настройку') }}</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
