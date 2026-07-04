<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Receipt {{ $sale->sale_number }}</title>
<style>
  body { font-family: Arial, sans-serif; color: #333; font-size: 12px; max-width: 700px; margin: 20px auto; padding: 20px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
  .store-name { font-size: 22px; font-weight: bold; }
  .receipt-title { font-size: 18px; font-weight: bold; color: #666; }
  table { width: 100%; border-collapse: collapse; margin: 12px 0; }
  th { background: #f5f5f5; text-align: left; padding: 8px; border: 1px solid #ddd; }
  td { padding: 8px; border: 1px solid #ddd; }
  .text-right { text-align: right; }
  .total-row td { font-weight: bold; background: #f9f9f9; }
  .payment-row td { background: #e8f5e9; }
  .footer { margin-top: 24px; text-align: center; color: #888; font-size: 11px; }
  {{ $template?->custom_css ?? '' }}
</style>
</head>
<body>
  @php
    $mergeContext = ['store' => $store, 'sale' => $sale, 'customer' => $sale->customer];
    $logoDataUri  = ($template?->show_logo ?? true) ? receipt_logo_data_uri($store?->logo) : null;
  @endphp

  <div class="header">
    <div>
      @if($logoDataUri)
      <img src="{{ $logoDataUri }}" style="max-height: 60px; margin-bottom: 6px;" />
      @endif
      <div class="store-name">{{ $store->name ?? config('app.name') }}</div>
      @if($store?->address)<div>{{ $store->address }}</div>@endif
      @if($store?->phone)<div>Phone: {{ $store->phone }}</div>@endif
      @if($template?->header_text)
      <div style="margin-top:4px; font-size:11px; color:#666;">{{ receipt_merge_tags($template->header_text, $mergeContext) }}</div>
      @endif
    </div>
    <div style="text-align:right;">
      <div class="receipt-title">RECEIPT</div>
      <div>No: <strong>{{ $sale->sale_number }}</strong></div>
      <div>Date: {{ $sale->sale_date->format('d M Y') }}</div>
      @if($sale->customer)
      <div>Customer: {{ $sale->customer->name }}</div>
      @endif
    </div>
  </div>

  <!-- Items -->
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Item</th>
        <th>SKU</th>
        <th class="text-right">Qty</th>
        <th class="text-right">Price</th>
        <th class="text-right">Total</th>
      </tr>
    </thead>
    <tbody>
      @foreach($sale->items as $i => $item)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>{{ $item->product_name }}</td>
        <td style="font-family:monospace;">{{ $item->sku }}</td>
        <td class="text-right">{{ number_format($item->quantity, 2) }}</td>
        <td class="text-right">{{ number_format($item->unit_price, 2) }}</td>
        <td class="text-right">{{ number_format($item->line_total, 2) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <!-- Totals -->
  <table style="width:300px; margin-left:auto;">
    <tr><td>Subtotal</td><td class="text-right">{{ number_format($sale->subtotal, 2) }}</td></tr>
    @if($sale->tax_amount > 0 && ($template?->show_tax_breakdown ?? true))
    <tr><td>Tax</td><td class="text-right">{{ number_format($sale->tax_amount, 2) }}</td></tr>
    @endif
    @if($sale->discount_amount > 0)
    <tr><td>Discount ({{ $sale->discount_type === 'percent' ? $sale->discount_amount.'%' : 'Fixed' }})</td>
        <td class="text-right">-{{ number_format($sale->discount_amount, 2) }}</td></tr>
    @endif
    <tr class="total-row"><td>TOTAL</td><td class="text-right">{{ number_format($sale->total, 2) }}</td></tr>
  </table>

  <!-- Payments -->
  <table style="width:300px; margin-left:auto; margin-top:8px;">
    @foreach($sale->payments as $payment)
    <tr class="payment-row">
      <td style="text-transform:capitalize;">{{ str_replace('_', ' ', $payment->method) }}</td>
      <td class="text-right">{{ number_format($payment->amount, 2) }}</td>
    </tr>
    @endforeach
    @if($sale->change_given > 0)
    <tr><td>Change</td><td class="text-right">{{ number_format($sale->change_given, 2) }}</td></tr>
    @endif
  </table>

  <div class="footer">
    {{ $template?->footer_text ? receipt_merge_tags($template->footer_text, $mergeContext) : 'Thank you for your business!' }}
  </div>

  @if($platformFooter?->is_enabled && $platformFooter?->footer_text)
  <div class="footer" style="margin-top:4px; color:#aaa;">{{ $platformFooter->footer_text }}</div>
  @endif
</body>
</html>
