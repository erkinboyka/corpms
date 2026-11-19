const passwordToggle = document.querySelector('.bm-password-toggle');
if (passwordToggle) {
    const field = document.getElementById(passwordToggle.getAttribute('aria-controls'));
    passwordToggle.hidden = false;
    passwordToggle.addEventListener('click', () => {
        const show = field.type === 'password';
        field.type = show ? 'text' : 'password';
        passwordToggle.setAttribute('aria-pressed', String(show));
        passwordToggle.firstElementChild.className = show ? 'ti ti-eye-off' : 'ti ti-eye';
    });
}
