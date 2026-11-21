{{-- Модалка рендерится вне карточек: transform при hover ломает position:fixed --}}
<div class="modal modal-blur fade" id="lmsSubmitModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="post" action="#" data-lms-submit-form data-base="{{ route('lms.assignments.submit', ['assignment' => '__ID__']) }}">
                @csrf
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0">{{ __('Сдать практическое задание') }}</h5>
                        <div class="text-secondary mt-1" data-lms-submit-title></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="lms-submit-body">{{ __('Что сделали') }}</label>
                        <textarea data-crm-rich id="lms-submit-body" name="body" class="form-control" rows="6" required placeholder="{{ __('Коротко опишите решение, выводы и ссылки на артефакты') }}"></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="lms-submit-link">{{ __('Ссылка на работу') }}</label>
                        <input id="lms-submit-link" name="link" type="url" class="form-control" placeholder="https://…">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                    <button class="btn btn-primary" type="submit"><i class="ti ti-send me-1"></i>{{ __('Отправить') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
    window.LMS_I18N = Object.assign(window.LMS_I18N || {}, {
        submitTitle: @json(__('Сдать практическое задание')),
        submitSent: @json(__('Работа отправлена')),
        submitFailed: @json(__('Не удалось отправить работу'))
    });
</script>
@endpush
