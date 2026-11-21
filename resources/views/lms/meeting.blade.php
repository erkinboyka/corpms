@extends('layouts.tabler')
@section('title', $meeting->title)
@section('content')
@php
    $backUrl = $meeting->lesson
        ? route('lms.lessons.show', $meeting->lesson)
        : ($meeting->course ? route('lms.courses.show', $meeting->course) : route('lms.index'));
@endphp
<div class="py-4 lms-page lms-meeting-page">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <a href="{{ $backUrl }}" class="btn btn-sm btn-ghost-secondary"><i class="ti ti-arrow-left me-1"></i>{{ __('Назад') }}</a>
        <span class="text-secondary">{{ $meeting->course?->title ?? __('Видеозвонок') }}</span>
    </div>

    <x-page-header :eyebrow="__('Комната')" :title="$meeting->title" :subtitle="__('Прямой эфир без внешнего сервиса — сигнализация через защищённый polling CRM')" />

    @include('lms.partials.meeting-stage', [
        'meeting' => $meeting,
        'myId' => $myId,
        'myName' => $myName,
        'participants' => $participants,
        'pollMs' => $pollMs,
        'iceServers' => $iceServers,
    ])
</div>
@endsection
