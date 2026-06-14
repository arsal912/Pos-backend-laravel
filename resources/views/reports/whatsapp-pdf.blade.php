<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #1a1a2e; background: #fff; }

  /* ── Header ── */
  .header { display: table; width: 100%; border-bottom: 3px solid #6366f1; padding-bottom: 12px; margin-bottom: 16px; }
  .header-logo { display: table-cell; vertical-align: middle; width: 70px; }
  .header-logo img { width: 60px; height: 60px; object-fit: contain; border-radius: 8px; }
  .header-logo .logo-placeholder { width: 60px; height: 60px; background: linear-gradient(135deg, #6366f1, #a78bfa); border-radius: 8px; display: table-cell; vertical-align: middle; text-align: center; color: white; font-size: 22px; font-weight: bold; }
  .header-info { display: table-cell; vertical-align: middle; padding-left: 14px; }
  .store-name { font-size: 18px; font-weight: bold; color: #1a1a2e; }
  .report-title { font-size: 13px; color: #6366f1; margin-top: 3px; font-weight: 600; }
  .report-period { font-size: 9px; color: #64748b; margin-top: 2px; }
  .header-meta { display: table-cell; vertical-align: top; text-align: right; font-size: 8px; color: #94a3b8; }

  /* ── Summary cards ── */
  .summary { display: table; width: 100%; margin-bottom: 16px; border-collapse: separate; border-spacing: 6px; }
  .summary-card { display: table-cell; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; text-align: center; }
  .summary-label { font-size: 8px; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; }
  .summary-value { font-size: 15px; font-weight: bold; color: #1a1a2e; margin-top: 3px; }
  .summary-value.money::before { content: ''; }

  /* ── Table ── */
  .data-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  .data-table th { background: #6366f1; color: #fff; font-size: 8px; font-weight: 600; padding: 7px 9px; text-align: left; text-transform: uppercase; letter-spacing: 0.05em; }
  .data-table th.right { text-align: right; }
  .data-table td { padding: 6px 9px; font-size: 9px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  .data-table td.right { text-align: right; font-family: monospace; }
  .data-table tr:nth-child(even) td { background: #f8fafc; }
  .data-table tr:last-child td { border-bottom: none; }

  /* ── Empty ── */
  .empty-state { text-align: center; padding: 30px; color: #94a3b8; font-size: 10px; }

  /* ── Footer ── */
  .footer { position: fixed; bottom: 0; left: 0; right: 0; border-top: 1px solid #e2e8f0; padding: 6px 0; font-size: 7px; color: #94a3b8; text-align: center; background: #fff; }
  .watermark { font-size: 8px; color: #64748b; font-style: italic; margin-top: 14px; text-align: right; }
</style>
</head>
<body>

<!-- Header -->
<div class="header">
  <div class="header-logo">
    @if($logoBase64)
      <img src="{{ $logoBase64 }}" alt="{{ $store->name }}">
    @else
      <div class="logo-placeholder">{{ strtoupper(substr($store->name, 0, 1)) }}</div>
    @endif
  </div>
  <div class="header-info">
    <div class="store-name">{{ $store->name }}</div>
    <div class="report-title">{{ $reportType }} Report</div>
    <div class="report-period">Period: {{ $dateFrom }} – {{ $dateTo }}</div>
    @if($store->address)
      <div class="report-period" style="margin-top:2px;">{{ $store->address }}{{ $store->city ? ', '.$store->city : '' }}</div>
    @endif
  </div>
  <div class="header-meta">
    Generated via WhatsApp<br>
    {{ $generatedAt }}<br>
    {{ $store->currency ?? 'PKR' }}
  </div>
</div>

<!-- Summary cards -->
@if(!empty($summary))
<div class="summary">
  @foreach($summary as $card)
    <div class="summary-card">
      <div class="summary-label">{{ $card['label'] ?? '' }}</div>
      <div class="summary-value {{ in_array($card['type'] ?? '', ['money']) ? 'money' : '' }}">
        @if(($card['type'] ?? '') === 'money')
          {{ number_format((float)($card['value'] ?? 0), 2) }}
        @elseif(($card['type'] ?? '') === 'pct')
          {{ $card['value'] ?? 0 }}%
        @else
          {{ is_numeric($card['value'] ?? '') ? number_format((float)$card['value']) : ($card['value'] ?? '—') }}
        @endif
      </div>
    </div>
  @endforeach
</div>
@endif

<!-- Data table -->
@if($rows->isNotEmpty() && !empty($columns))
<table class="data-table">
  <thead>
    <tr>
      @foreach($columns as $col)
        <th class="{{ in_array($col['align'] ?? 'left', ['right']) ? 'right' : '' }}">
          {{ $col['label'] ?? $col['key'] ?? '' }}
        </th>
      @endforeach
    </tr>
  </thead>
  <tbody>
    @foreach($rows->take(100) as $row)
      <tr>
        @foreach($columns as $col)
          @php
            $key  = $col['key'] ?? '';
            $type = $col['type'] ?? 'string';
            $val  = is_array($row) ? ($row[$key] ?? '—') : ($row->$key ?? '—');
          @endphp
          <td class="{{ in_array($col['align'] ?? 'left', ['right']) ? 'right' : '' }}">
            @if($type === 'money')
              {{ is_numeric($val) ? number_format((float)$val, 2) : $val }}
            @elseif($type === 'int')
              {{ is_numeric($val) ? number_format((int)$val) : $val }}
            @elseif($type === 'pct')
              {{ $val }}%
            @elseif($type === 'date')
              {{ $val && $val !== '—' ? \Carbon\Carbon::parse($val)->format('d M Y') : $val }}
            @else
              {{ $val }}
            @endif
          </td>
        @endforeach
      </tr>
    @endforeach
  </tbody>
</table>
@if($rows->count() > 100)
  <p style="font-size:8px;color:#94a3b8;margin-top:6px;">Showing top 100 of {{ $rows->count() }} records.</p>
@endif
@else
  <div class="empty-state">No data found for this period.</div>
@endif

<div class="watermark">This report was generated automatically via WhatsApp · {{ config('app.name', 'POS System') }}</div>

<!-- Footer -->
<div class="footer">
  {{ $store->name }} · {{ $store->email }} · Generated {{ $generatedAt }} · Confidential
</div>

</body>
</html>
