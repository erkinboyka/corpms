@include('errors.partials.card', [
    'code' => 403,
    'title' => 'Доступ запрещён',
    'description' => 'У вашей роли нет прав для выполнения этого действия. Обратитесь к администратору компании, если доступ нужен.',
    // сообщение наше (EnsurePermission) и безопасное — показываем
    'message' => isset($exception) && trim($exception?->getMessage() ?? '') !== '' ? $exception->getMessage() : null,
])
