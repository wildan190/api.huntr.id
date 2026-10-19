<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#f5f5f5;font-family:Arial,sans-serif;color:#202124;">
<div style="max-width:600px;margin:24px auto;padding:24px;background:#fff;">
    <h1 style="font-size:20px;margin:0 0 16px;">{{ $bill['paid'] ? 'Pembayaran diterima' : 'Tagihan subscription Huntr' }}</h1>
    <p>Halo {{ $bill['company'] }},</p>
    <p>{{ $bill['paid'] ? 'Pembayaran Anda telah diterima.' : 'Tagihan Anda telah dibuat. Silakan selesaikan pembayaran menggunakan rincian berikut.' }}</p>
    <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <tr><td style="padding:8px;border-bottom:1px solid #eee;">Referensi</td><td style="padding:8px;border-bottom:1px solid #eee;"><strong>{{ $bill['order_id'] }}</strong></td></tr>
        <tr><td style="padding:8px;border-bottom:1px solid #eee;">Paket / add-on</td><td style="padding:8px;border-bottom:1px solid #eee;">{{ $bill['item'] }} @if($bill['quantity'] > 1) &times; {{ $bill['quantity'] }} @endif</td></tr>
        @foreach($bill['addons'] ?? [] as $addon)
            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Add-on</td><td style="padding:8px;border-bottom:1px solid #eee;">{{ $addon['name'] }} &times; {{ $addon['quantity'] }} — Rp {{ number_format($addon['price'] * $addon['quantity'], 0, ',', '.') }} @if($addon['billing_cycle'] === 'monthly') / bulan @endif</td></tr>
        @endforeach
        <tr><td style="padding:8px;border-bottom:1px solid #eee;">Periode</td><td style="padding:8px;border-bottom:1px solid #eee;">{{ ['annual' => 'Tahunan', 'monthly' => 'Bulanan', 'one_time' => 'Sekali bayar'][$bill['cycle']] ?? $bill['cycle'] }}</td></tr>
        <tr><td style="padding:8px;border-bottom:1px solid #eee;">Total</td><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Rp {{ number_format($bill['amount'], 0, ',', '.') }}</strong></td></tr>
        @if(!$bill['paid'])
            <tr><td style="padding:8px;border-bottom:1px solid #eee;">Metode</td><td style="padding:8px;border-bottom:1px solid #eee;">{{ strtoupper(str_replace('_', ' ', $bill['method'])) }}</td></tr>
            @if(!empty($bill['payment_info']['va_number']))<tr><td style="padding:8px;border-bottom:1px solid #eee;">Virtual account</td><td style="padding:8px;border-bottom:1px solid #eee;"><strong>{{ $bill['payment_info']['va_number'] }}</strong></td></tr>@endif
            @if(!empty($bill['payment_info']['biller_code']))<tr><td style="padding:8px;border-bottom:1px solid #eee;">Biller code</td><td style="padding:8px;border-bottom:1px solid #eee;">{{ $bill['payment_info']['biller_code'] }}</td></tr>@endif
            @if(!empty($bill['payment_info']['bill_key']))<tr><td style="padding:8px;border-bottom:1px solid #eee;">Bill key</td><td style="padding:8px;border-bottom:1px solid #eee;">{{ $bill['payment_info']['bill_key'] }}</td></tr>@endif
            @if(!empty($bill['payment_info']['expiry_time']))<tr><td style="padding:8px;border-bottom:1px solid #eee;">Batas pembayaran</td><td style="padding:8px;border-bottom:1px solid #eee;">{{ $bill['payment_info']['expiry_time'] }}</td></tr>@endif
        @endif
    </table>
    <p style="font-size:12px;color:#666;margin-top:24px;">Terima kasih telah menggunakan Huntr.</p>
</div>
</body>
</html>
