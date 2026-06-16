@php
    use App\Support\SignatureQr;
    $isSigned = !empty($signedAt);
    $qrPayload = $isSigned
        ? SignatureQr::payload($docType, $docId, $role, $signerName, $signedAt)
        : null;
@endphp

<div class="signature-block">
    <div class="signature-block__label">{{ $label }}</div>

    @if($isSigned)
        <img
            src="{{ SignatureQr::imageUrl($qrPayload) }}"
            alt="Signature QR — {{ $signerName }}"
            class="signature-block__qr"
            width="100"
            height="100"
        />
        <div class="signature-block__meta">Signed: {{ $signedAtFormatted }}</div>
    @else
        <div class="signature-block__pending">Awaiting signature</div>
    @endif

    <div class="signature-block__name">{{ $signerName ?: '—' }}</div>
    <div class="signature-block__position">{{ $signerPosition ?: '—' }}</div>
</div>
