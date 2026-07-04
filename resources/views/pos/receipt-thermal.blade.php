<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Courier New', monospace; font-size: 11px; width: 80mm; color: #000; }
  .center { text-align: center; }
  .right { text-align: right; }
  .bold { font-weight: bold; }
  .divider { border-top: 1px dashed #000; margin: 4px 0; }
  table { width: 100%; }
  td { padding: 1px 0; }
  .store-name { font-size: 16px; font-weight: bold; }
  .total-row td { font-size: 13px; font-weight: bold; }
  {{ $template?->custom_css ?? '' }}
</style>
</head>
<body>
  @php
    $mergeContext = ['store' => $store, 'sale' => $sale, 'customer' => $sale->customer];
    $logoDataUri  = ($template?->show_logo ?? true) ? receipt_logo_data_uri($store?->logo) : null;
  @endphp

  <!-- Store Header -->
  @if($logoDataUri)
  <div class="center" style="margin-bottom: 6px;"><img src="{{ $logoDataUri }}" style="max-height: 60px;" /></div>
  @endif
  <div class="center" style="margin-bottom: 8px;">
    <div class="store-name">{{ $store->name ?? config('app.name') }}</div>
    @if($store?->address)<div>{{ $store->address }}</div>@endif
    @if($store?->phone)<div>{{ $store->phone }}</div>@endif
    @if($template?->header_text)
    <div style="margin-top:4px;">{{ receipt_merge_tags($template->header_text, $mergeContext) }}</div>
    @endif
  </div>

  <div class="divider"></div>

  <!-- Sale Info -->
  <table>
    <tr><td>Receipt #:</td><td class="right bold">{{ $sale->sale_number }}</td></tr>
    <tr><td>Date:</td><td class="right">{{ $sale->sale_date->format('d/m/Y H:i') }}</td></tr>
    @if($sale->customer)<tr><td>Customer:</td><td class="right">{{ $sale->customer->name }}</td></tr>@endif
  </table>

  <div class="divider"></div>

  <!-- Items -->
  <table>
    @foreach($sale->items as $item)
    <tr>
      <td colspan="2">{{ $item->product_name }}</td>
    </tr>
    <tr>
      <td style="padding-left:8px;">{{ number_format($item->quantity, 2) }} x {{ number_format($item->unit_price, 2) }}</td>
      <td class="right">{{ number_format($item->line_total, 2) }}</td>
    </tr>
    @endforeach
  </table>

  <div class="divider"></div>

  <!-- Totals -->
  <table>
    <tr><td>Subtotal:</td><td class="right">{{ number_format($sale->subtotal, 2) }}</td></tr>
    @if($sale->tax_amount > 0 && ($template?->show_tax_breakdown ?? true))
    <tr><td>Tax:</td><td class="right">{{ number_format($sale->tax_amount, 2) }}</td></tr>
    @endif
    @if($sale->discount_amount > 0)
    <tr><td>Discount:</td><td class="right">-{{ number_format($sale->discount_amount, 2) }}</td></tr>
    @endif
    <tr class="total-row"><td>TOTAL:</td><td class="right">{{ number_format($sale->total, 2) }}</td></tr>
  </table>

  <div class="divider"></div>

  <!-- Payments -->
  <table>
    @foreach($sale->payments as $payment)
    <tr>
      <td class="bold" style="text-transform:capitalize;">{{ str_replace('_',' ',$payment->method) }}</td>
      <td class="right">{{ number_format($payment->amount, 2) }}</td>
    </tr>
    @endforeach
    @if($sale->change_given > 0)
    <tr><td>Change:</td><td class="right">{{ number_format($sale->change_given, 2) }}</td></tr>
    @endif
  </table>

  <div class="divider"></div>
  <div class="center" style="margin-top:8px; font-size:10px;">
    {{ $template?->footer_text ? receipt_merge_tags($template->footer_text, $mergeContext) : 'Thank you for your purchase!' }}
  </div>

  @if($platformFooter?->is_enabled && $platformFooter?->footer_text)
  <div class="divider"></div>
  <div class="center" style="margin-top:4px; font-size:9px; color:#555;">{{ $platformFooter->footer_text }}</div>
  @endif
</body>
</html>
