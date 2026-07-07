<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');

    * { box-sizing: border-box; }
    body {
        font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
        padding: 32px;
        color: #1f2937;
        line-height: 1.55;
        font-size: 13px;
        background: #fff;
    }

    .print-doc { max-width: 900px; margin: 0 auto; }

    /* Platform header */
    .platform-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 24px;
        padding-bottom: 16px;
        border-bottom: 3px solid {{ $accentColor ?? '#f97316' }};
        margin-bottom: 24px;
    }
    .platform-brand { display: flex; align-items: center; gap: 14px; }
    .platform-logo {
        height: 96px;
        width: auto;
        flex-shrink: 0;
        object-fit: contain;
    }
    .platform-name { font-size: 22px; font-weight: 900; color: #111827; letter-spacing: -0.4px; }
    .platform-tagline { font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; margin-top: 2px; }

    .doc-meta { text-align: right; flex-shrink: 0; }
    .doc-meta h1 { margin: 0; font-size: 22px; font-weight: 900; color: #111827; letter-spacing: 0.02em; }
    .doc-meta .doc-subtitle { margin: 4px 0 0; font-size: 12px; color: #6b7280; }
    .doc-meta .doc-number { margin: 8px 0 0; font-size: 14px; font-weight: 700; color: {{ $accentColor ?? '#f97316' }}; }

    /* Company parties */
    .parties-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 28px;
    }
    .party-card {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 16px;
        background: #fafafa;
    }
    .party-label {
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: {{ $accentColor ?? '#f97316' }};
        margin-bottom: 10px;
    }
    .party-row { display: flex; align-items: flex-start; gap: 12px; }
    .party-logo-wrap {
        width: 56px;
        height: 56px;
        border-radius: 10px;
        border: 1px solid #e5e7eb;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
    }
    .party-logo { max-width: 100%; max-height: 100%; object-fit: contain; }
    .party-logo-fallback {
        font-size: 18px;
        font-weight: 800;
        color: {{ $accentColor ?? '#f97316' }};
    }
    .party-name { font-size: 15px; font-weight: 800; color: #111827; margin: 0 0 4px; }
    .party-detail { font-size: 12px; color: #4b5563; margin: 2px 0; }

    .section-title {
        font-size: 11px;
        text-transform: uppercase;
        color: #6b7280;
        font-weight: 800;
        letter-spacing: 0.08em;
        margin-bottom: 10px;
        border-bottom: 1px solid #e5e7eb;
        padding-bottom: 6px;
    }

    table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    th {
        background: #f3f4f6;
        text-align: left;
        padding: 10px 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 2px solid #d1d5db;
        color: #374151;
    }
    td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
    .total-row { font-weight: 900; font-size: 14px; background: #fff7ed; }

    .print-footer {
        margin-top: 40px;
        font-size: 11px;
        color: #6b7280;
        border-top: 1px solid #e5e7eb;
        padding-top: 16px;
        text-align: center;
    }
    .powered-by {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
        font-size: 12px;
    }
    .powered-by strong { color: {{ $accentColor ?? '#f97316' }}; font-size: 15px; }

    .signature-section { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 40px; }
    .signature-block { border-top: 1px solid #999; padding-top: 16px; text-align: center; min-height: 220px; }
    .signature-block__label { font-size: 12px; font-weight: 700; margin-bottom: 8px; }
    .signature-block__qr { display: block; margin: 8px auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 4px; background: #fff; }
    .signature-block__meta { font-size: 11px; color: #666; margin: 6px 0; }
    .signature-block__pending { font-size: 11px; color: #999; margin: 36px 0 12px; font-style: italic; }
    .signature-block__name { font-weight: 700; margin-top: 8px; font-size: 13px; }
    .signature-block__position { font-size: 11px; color: #666; margin-top: 4px; }

    @media print {
        body { padding: 0; }
        .no-print { display: none; }
        .party-card { background: #fff; }
    }
</style>
