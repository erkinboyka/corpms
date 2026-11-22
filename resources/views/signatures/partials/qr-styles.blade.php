<style>
    .signature-qr-card { max-width: 460px; margin: 0 auto; }
    .signature-qr-icon {
        width: 52px; height: 52px; border-radius: 16px; display: grid; place-items: center;
        font-size: 26px; margin-bottom: 18px;
        background: var(--tblr-primary-lt, rgba(32, 107, 196, .12));
        color: var(--tblr-primary, #206bc4);
    }
    .signature-qr-icon--done { background: rgba(30, 167, 91, .12); color: #1ea75b; }
    .signature-qr-icon--error { background: rgba(217, 83, 79, .12); color: #d9534f; }
    .signature-qr-doc {
        border: 1px solid var(--tblr-border-color, #dce0e5); border-radius: 12px;
        padding: 14px 16px; margin-bottom: 16px; background: var(--tblr-bg-surface, transparent);
    }
    .signature-qr-meta { margin: 0 0 20px; }
    .signature-qr-meta > div {
        display: flex; justify-content: space-between; gap: 12px;
        padding: 7px 0; border-bottom: 1px dashed var(--tblr-border-color, #e6e9ee);
    }
    .signature-qr-meta dt { margin: 0; color: var(--tblr-muted, #7c8693); font-weight: 500; }
    .signature-qr-meta dd { margin: 0; text-align: right; word-break: break-all; }
    .signature-qr-body {
        border: 1px solid var(--tblr-border-color, #dce0e5); border-radius: 12px;
        padding: 14px 16px; background: var(--tblr-bg-surface, transparent);
    }
    .signature-qr-body-text { max-height: 220px; overflow-y: auto; font-size: .875rem; }
    .signature-qr-hint { font-size: .8125rem; line-height: 1.45; }
</style>
