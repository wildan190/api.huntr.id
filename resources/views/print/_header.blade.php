@php
    $buyerInitial = strtoupper(substr($buyerName ?? 'B', 0, 1));
    $vendorInitial = strtoupper(substr($vendorName ?? 'V', 0, 1));
@endphp

<div class="platform-header">
    <div class="platform-brand">
        <div class="platform-emblem">H</div>
        <div>
            <div class="platform-name">Huntr.id</div>
            <div class="platform-tagline">Enterprise E-Procurement Platform</div>
        </div>
    </div>
    <div class="doc-meta">
        <h1>{{ $docTitle }}</h1>
        @if(!empty($docSubtitle))
            <div class="doc-subtitle">{{ $docSubtitle }}</div>
        @endif
        @if(!empty($docNumber))
            <div class="doc-number">#{{ $docNumber }}</div>
        @endif
    </div>
</div>

<div class="parties-grid">
    <div class="party-card">
        <div class="party-label">{{ $buyerLabel ?? 'Buyer / Pembeli' }}</div>
        <div class="party-row">
            <div class="party-logo-wrap">
                @if(!empty($buyerLogoUrl))
                    <img src="{{ $buyerLogoUrl }}" alt="{{ $buyerName }}" class="party-logo">
                @else
                    <span class="party-logo-fallback">{{ $buyerInitial }}</span>
                @endif
            </div>
            <div>
                <p class="party-name">{{ $buyerName ?? 'N/A' }}</p>
                @if(!empty($buyerAddress) && $buyerAddress !== 'N/A')
                    <p class="party-detail">{{ $buyerAddress }}</p>
                @endif
                @if(!empty($buyerTaxId))
                    <p class="party-detail">NPWP: {{ $buyerTaxId }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="party-card">
        <div class="party-label">{{ $vendorLabel ?? 'Vendor / Pemasok' }}</div>
        <div class="party-row">
            <div class="party-logo-wrap">
                @if(!empty($vendorLogoUrl))
                    <img src="{{ $vendorLogoUrl }}" alt="{{ $vendorName }}" class="party-logo">
                @else
                    <span class="party-logo-fallback">{{ $vendorInitial }}</span>
                @endif
            </div>
            <div>
                <p class="party-name">{{ $vendorName ?? 'N/A' }}</p>
                @if(!empty($vendorAddress) && $vendorAddress !== 'N/A')
                    <p class="party-detail">{{ $vendorAddress }}</p>
                @endif
                @if(!empty($vendorTaxId))
                    <p class="party-detail">NPWP: {{ $vendorTaxId }}</p>
                @endif
            </div>
        </div>
    </div>
</div>
