<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds system (platform-level) message templates into each tenant DB.
 * These templates are protected from deletion but can be duplicated by tenants.
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [

            // ── SMS - Transactional ───────────────────────────────────────────
            [
                'name'        => 'Receipt - SMS',
                'description' => 'Sent automatically after a successful sale',
                'channel'     => 'sms',
                'type'        => 'transactional',
                'body'        => 'Thank you {{customer_name}}! Your purchase of {{currency}}{{amount}} at {{store_name}} is confirmed. Receipt: {{receipt_number}}.',
                'variables'   => [
                    ['key' => 'customer_name',  'label' => 'Customer Name',   'example' => 'John Doe'],
                    ['key' => 'currency',        'label' => 'Currency Symbol', 'example' => '$'],
                    ['key' => 'amount',          'label' => 'Sale Amount',     'example' => '1,250.00'],
                    ['key' => 'store_name',      'label' => 'Store Name',      'example' => 'My Store'],
                    ['key' => 'receipt_number',  'label' => 'Receipt Number',  'example' => 'REC-00123'],
                ],
            ],
            [
                'name'        => 'OTP / Verification - SMS',
                'description' => 'One-time password for customer login or account actions',
                'channel'     => 'sms',
                'type'        => 'transactional',
                'body'        => 'Your {{store_name}} verification code is {{otp_code}}. Valid for 10 minutes. Do not share this code.',
                'variables'   => [
                    ['key' => 'store_name', 'label' => 'Store Name',    'example' => 'My Store'],
                    ['key' => 'otp_code',   'label' => 'OTP Code',      'example' => '483921'],
                ],
            ],
            [
                'name'        => 'Order Ready - SMS',
                'description' => 'Notifies customer their order is ready for pickup',
                'channel'     => 'sms',
                'type'        => 'transactional',
                'body'        => 'Hi {{customer_name}}, your order #{{order_number}} at {{store_name}} is ready for pickup. Thank you!',
                'variables'   => [
                    ['key' => 'customer_name', 'label' => 'Customer Name',  'example' => 'John Doe'],
                    ['key' => 'order_number',  'label' => 'Order Number',   'example' => 'ORD-0042'],
                    ['key' => 'store_name',    'label' => 'Store Name',     'example' => 'My Store'],
                ],
            ],

            // ── SMS - Marketing / Reminder ────────────────────────────────────
            [
                'name'        => 'Birthday Offer - SMS',
                'description' => 'Birthday discount sent to customers on their birthday',
                'channel'     => 'sms',
                'type'        => 'birthday',
                'body'        => 'Happy Birthday {{customer_name}}! Celebrate with {{discount}}% off at {{store_name}} today. Valid until {{valid_until}}. Reply STOP to opt out.',
                'variables'   => [
                    ['key' => 'customer_name', 'label' => 'Customer Name',   'example' => 'John'],
                    ['key' => 'discount',      'label' => 'Discount %',      'example' => '15'],
                    ['key' => 'store_name',    'label' => 'Store Name',      'example' => 'My Store'],
                    ['key' => 'valid_until',   'label' => 'Offer Expiry',    'example' => '31 Dec 2025'],
                ],
            ],
            [
                'name'        => 'Promotional Offer - SMS',
                'description' => 'General marketing SMS for promotions and offers',
                'channel'     => 'sms',
                'type'        => 'marketing',
                'body'        => 'Hi {{customer_name}}, exclusive offer from {{store_name}}: {{offer_details}}. Valid till {{valid_until}}. Reply STOP to opt out.',
                'variables'   => [
                    ['key' => 'customer_name', 'label' => 'Customer Name',  'example' => 'Valued Customer'],
                    ['key' => 'store_name',    'label' => 'Store Name',     'example' => 'My Store'],
                    ['key' => 'offer_details', 'label' => 'Offer Details',  'example' => 'Buy 2 Get 1 Free on all items'],
                    ['key' => 'valid_until',   'label' => 'Offer Expiry',   'example' => '31 Dec 2025'],
                ],
            ],
            [
                'name'        => 'Low Balance Reminder - SMS',
                'description' => 'Reminds customer their store credit / loyalty balance is low',
                'channel'     => 'sms',
                'type'        => 'reminder',
                'body'        => 'Hi {{customer_name}}, your {{store_name}} credit balance is {{currency}}{{balance}}. Top up to enjoy your benefits!',
                'variables'   => [
                    ['key' => 'customer_name', 'label' => 'Customer Name',  'example' => 'John'],
                    ['key' => 'store_name',    'label' => 'Store Name',     'example' => 'My Store'],
                    ['key' => 'currency',      'label' => 'Currency Symbol','example' => '$'],
                    ['key' => 'balance',       'label' => 'Balance Amount', 'example' => '50.00'],
                ],
            ],

            // ── Email - Transactional ─────────────────────────────────────────
            [
                'name'        => 'Receipt - Email',
                'description' => 'Detailed receipt email sent after a successful sale',
                'channel'     => 'email',
                'subject'     => 'Your receipt from {{store_name}} - {{receipt_number}}',
                'type'        => 'transactional',
                'body'        => "<p>Hi {{customer_name}},</p>\n<p>Thank you for shopping at <strong>{{store_name}}</strong>.</p>\n<p><strong>Receipt #{{receipt_number}}</strong><br>Date: {{sale_date}}<br>Total: <strong>{{currency}}{{amount}}</strong></p>\n<p>{{items_table}}</p>\n<p>We appreciate your business!</p>\n<p>- {{store_name}} Team</p>",
                'variables'   => [
                    ['key' => 'customer_name',  'label' => 'Customer Name',   'example' => 'John Doe'],
                    ['key' => 'store_name',     'label' => 'Store Name',      'example' => 'My Store'],
                    ['key' => 'receipt_number', 'label' => 'Receipt Number',  'example' => 'REC-00123'],
                    ['key' => 'sale_date',      'label' => 'Sale Date',       'example' => '8 Jun 2026'],
                    ['key' => 'currency',       'label' => 'Currency Symbol', 'example' => '$'],
                    ['key' => 'amount',         'label' => 'Total Amount',    'example' => '1,250.00'],
                    ['key' => 'items_table',    'label' => 'Items List (HTML)','example' => '(rendered by system)'],
                ],
            ],
            [
                'name'        => 'Welcome Email',
                'description' => 'Sent when a customer account is created',
                'channel'     => 'email',
                'subject'     => 'Welcome to {{store_name}}!',
                'type'        => 'transactional',
                'body'        => "<p>Hi {{customer_name}},</p>\n<p>Welcome to <strong>{{store_name}}</strong>! We're glad to have you.</p>\n<p>Your account has been created. You can now enjoy exclusive offers and track your purchase history.</p>\n<p>See you soon,<br>{{store_name}} Team</p>",
                'variables'   => [
                    ['key' => 'customer_name', 'label' => 'Customer Name', 'example' => 'John Doe'],
                    ['key' => 'store_name',    'label' => 'Store Name',    'example' => 'My Store'],
                ],
            ],

            // ── Email - Marketing ─────────────────────────────────────────────
            [
                'name'        => 'Birthday Offer - Email',
                'description' => 'Birthday email with personalised discount',
                'channel'     => 'email',
                'subject'     => 'Happy Birthday {{customer_name}} - A special gift from {{store_name}}',
                'type'        => 'birthday',
                'body'        => "<p>Hi {{customer_name}},</p>\n<p>🎂 Happy Birthday from all of us at <strong>{{store_name}}</strong>!</p>\n<p>As a birthday treat, enjoy <strong>{{discount}}% off</strong> your next purchase. Use code <strong>{{promo_code}}</strong> or simply visit us before <strong>{{valid_until}}</strong>.</p>\n<p>We look forward to celebrating with you!</p>\n<p>- {{store_name}} Team</p>\n<hr>\n<p style=\"font-size:11px;color:#999;\">You received this because you are a valued customer. To unsubscribe, <a href=\"{{unsubscribe_url}}\">click here</a>.<br>{{store_address}}</p>",
                'variables'   => [
                    ['key' => 'customer_name',    'label' => 'Customer Name',    'example' => 'John'],
                    ['key' => 'store_name',       'label' => 'Store Name',       'example' => 'My Store'],
                    ['key' => 'discount',         'label' => 'Discount %',       'example' => '15'],
                    ['key' => 'promo_code',       'label' => 'Promo Code',       'example' => 'BDAY15'],
                    ['key' => 'valid_until',      'label' => 'Offer Expiry',     'example' => '31 Dec 2025'],
                    ['key' => 'unsubscribe_url',  'label' => 'Unsubscribe URL',  'example' => '(auto-generated)'],
                    ['key' => 'store_address',    'label' => 'Store Address',    'example' => '(from settings)'],
                ],
            ],
            [
                'name'        => 'Promotional Campaign - Email',
                'description' => 'General marketing email for campaigns and offers',
                'channel'     => 'email',
                'subject'     => 'Special offer for you from {{store_name}}',
                'type'        => 'marketing',
                'body'        => "<p>Hi {{customer_name}},</p>\n<p>We have an exclusive offer just for you at <strong>{{store_name}}</strong>:</p>\n<p><strong>{{offer_details}}</strong></p>\n<p>This offer is valid until <strong>{{valid_until}}</strong>. Don't miss out!</p>\n<p>- {{store_name}} Team</p>\n<hr>\n<p style=\"font-size:11px;color:#999;\">To unsubscribe, <a href=\"{{unsubscribe_url}}\">click here</a>.<br>{{store_address}}</p>",
                'variables'   => [
                    ['key' => 'customer_name',   'label' => 'Customer Name',  'example' => 'Valued Customer'],
                    ['key' => 'store_name',      'label' => 'Store Name',     'example' => 'My Store'],
                    ['key' => 'offer_details',   'label' => 'Offer Details',  'example' => 'Buy 2 Get 1 Free'],
                    ['key' => 'valid_until',     'label' => 'Offer Expiry',   'example' => '31 Dec 2025'],
                    ['key' => 'unsubscribe_url', 'label' => 'Unsubscribe URL','example' => '(auto-generated)'],
                    ['key' => 'store_address',   'label' => 'Store Address',  'example' => '(from settings)'],
                ],
            ],

            // ── WhatsApp - Transactional ──────────────────────────────────────
            [
                'name'                   => 'Receipt - WhatsApp',
                'description'            => 'WhatsApp receipt message after a sale',
                'channel'                => 'whatsapp',
                'type'                   => 'transactional',
                'whatsapp_template_name' => 'pos_receipt',
                'body'                   => "Hello {{customer_name}},\n\nThank you for shopping at *{{store_name}}*!\n\nReceipt: *{{receipt_number}}*\nDate: {{sale_date}}\nTotal: *{{currency}}{{amount}}*\n\nWe hope to see you again soon!",
                'variables'              => [
                    ['key' => 'customer_name',  'label' => 'Customer Name',   'example' => 'John Doe'],
                    ['key' => 'store_name',     'label' => 'Store Name',      'example' => 'My Store'],
                    ['key' => 'receipt_number', 'label' => 'Receipt Number',  'example' => 'REC-00123'],
                    ['key' => 'sale_date',      'label' => 'Sale Date',       'example' => '8 Jun 2026'],
                    ['key' => 'currency',       'label' => 'Currency Symbol', 'example' => '$'],
                    ['key' => 'amount',         'label' => 'Total Amount',    'example' => '1,250.00'],
                ],
            ],
            [
                'name'                   => 'Promotional Offer - WhatsApp',
                'description'            => 'Marketing WhatsApp message (requires pre-approved template)',
                'channel'                => 'whatsapp',
                'type'                   => 'marketing',
                'whatsapp_template_name' => 'pos_promo_offer',
                'body'                   => "Hello {{customer_name}},\n\nExclusive offer from *{{store_name}}*:\n{{offer_details}}\n\nValid until: {{valid_until}}\n\nReply *STOP* to opt out.",
                'variables'              => [
                    ['key' => 'customer_name', 'label' => 'Customer Name',  'example' => 'John'],
                    ['key' => 'store_name',    'label' => 'Store Name',     'example' => 'My Store'],
                    ['key' => 'offer_details', 'label' => 'Offer Details',  'example' => 'Buy 2 Get 1 Free'],
                    ['key' => 'valid_until',   'label' => 'Offer Expiry',   'example' => '31 Dec 2025'],
                ],
            ],
        ];

        foreach ($templates as $tpl) {
            MessageTemplate::updateOrCreate(
                ['name' => $tpl['name'], 'channel' => $tpl['channel']],
                array_merge($tpl, ['is_system' => true, 'is_active' => true])
            );
        }
    }
}
