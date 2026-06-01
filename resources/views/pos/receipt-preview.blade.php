<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Courier New', monospace; font-size: 12px; padding: 16px;
         {{ $template->type === 'a4' ? 'max-width: 700px; font-family: Arial, sans-serif;' : 'width: 80mm;' }} }
  .center { text-align: center; }
  .right { text-align: right; }
  .bold { font-weight: bold; }
  .divider { border-top: 1px dashed #999; margin: 8px 0; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 4px 6px; }
  th { background: #f0f0f0; text-align: left; border: 1px solid #ddd; }
  td { border: 1px solid #eee; }
  {{ $template->custom_css ?? '' }}
</style>
</head>
<body>
  @if($template->show_logo && $store?->logo)
  <div class="center" style="margin-bottom: 8px;">
    <img src="{{ $store->logo }}" style="max-height: 60px;" />
  </div>
  @endif

  <div class="center bold" style="font-size: 16px; margin-bottom: 4px;">
    {{ $store->name ?? 'Store Name' }}
  </div>
  @if($store?->address)
  <div class="center" style="font-size: 11px; color: #666;">{{ $store->address }}</div>
  @endif

  @if($template->header_text)
  <div class="center" style="margin: 6px 0; font-size: 11px; color: #555;">{{ $template->header_text }}</div>
  @endif

  <div class="divider"></div>

  <!-- Sample sale data -->
  <table style="margin-bottom: 8px; {{ $template->type === 'a4' ? '' : 'font-family: monospace; font-size: 11px;' }}">
    <tr><td>Receipt #:</td><td class="right bold">S-2026-00000001</td></tr>
    <tr><td>Date:</td><td class="right">{{ now()->format('d/m/Y H:i') }}</td></tr>
    <tr><td>Customer:</td><td class="right">Ahmed Khan</td></tr>
    <tr><td>Cashier:</td><td class="right">Demo User</td></tr>
  </table>

  <div class="divider"></div>

  <!-- Sample items -->
  @if($template->type === 'a4')
  <table style="margin-bottom: 8px;">
    <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th class="right">Total</th></tr></thead>
    <tbody>
      <tr><td>Samsung Galaxy S24</td><td>1</td><td>85,000.00</td><td class="right">85,000.00</td></tr>
      <tr><td>Screen Protector</td><td>2</td><td>500.00</td><td class="right">1,000.00</td></tr>
    </tbody>
  </table>
  @else
  <div style="font-size: 11px;">
    <div>Samsung Galaxy S24</div>
    <div style="display:flex; justify-content:space-between;"><span style="padding-left:8px;">1 x 85,000.00</span><span>85,000.00</span></div>
    <div>Screen Protector</div>
    <div style="display:flex; justify-content:space-between;"><span style="padding-left:8px;">2 x 500.00</span><span>1,000.00</span></div>
  </div>
  @endif

  <div class="divider"></div>

  <!-- Totals -->
  <table style="{{ $template->type === 'a4' ? 'width:300px; margin-left:auto;' : '' }}">
    <tr><td>Subtotal:</td><td class="right">86,000.00</td></tr>
    @if($template->show_tax_breakdown)
    <tr><td>GST (17%):</td><td class="right">14,620.00</td></tr>
    @endif
    <tr><td>Discount:</td><td class="right">-1,000.00</td></tr>
    <tr class="bold"><td>TOTAL:</td><td class="right" style="font-size: 14px;">99,620.00</td></tr>
    <tr><td>Cash:</td><td class="right">100,000.00</td></tr>
    <tr><td>Change:</td><td class="right">380.00</td></tr>
  </table>

  @if($template->footer_text)
  <div class="divider"></div>
  <div class="center" style="font-size: 11px; color: #555; margin-top: 6px;">{{ $template->footer_text }}</div>
  @endif

  @if($template->show_qr_code)
  <div class="center" style="margin-top: 12px;">
    <div style="width:80px; height:80px; background:#f0f0f0; margin: 0 auto; display:flex; align-items:center; justify-content:center; font-size:9px; color:#999;">QR CODE</div>
    <p style="font-size:9px; color:#999; margin-top:4px;">S-2026-00000001</p>
  </div>
  @endif

  <div class="center" style="margin-top: 10px; font-size: 10px; color: #aaa;">
    Thank you for your purchase!
  </div>
</body>
</html>
