<?php

namespace Database\Seeders;

use App\Models\LandingPageSection;
use App\Models\LandingPageSetting;
use Illuminate\Database\Seeder;

class LandingPageSeeder extends Seeder
{
    public function run(): void
    {
        $settings = LandingPageSetting::updateOrCreate(
            ['id' => 1],
            [
                'is_enabled' => true,
                'site_title' => 'POS System — Modern Point of Sale for Every Business',
                'site_description' => 'Run your business smarter with our cloud-based POS. Inventory, sales, customers — all in one place.',
                'meta_keywords' => 'POS, point of sale, retail, inventory, billing, Pakistan POS, cloud POS',
                'primary_color' => '#4F46E5',
            ]
        );

        $sections = [
            [
                'section_key' => 'hero',
                'title' => 'Run Your Business Smarter',
                'subtitle' => 'A modern POS that grows with your business — from a single store to a multi-branch enterprise.',
                'content' => [
                    'cta_text' => 'Start Free Trial',
                    'cta_secondary' => 'Watch Demo',
                    'badge' => '14-day free trial • No credit card required',
                    'image' => null,
                ],
                'sort_order' => 1,
            ],
            [
                'section_key' => 'features',
                'title' => 'Everything You Need to Run Your Store',
                'subtitle' => 'Powerful features designed for modern businesses',
                'content' => [
                    'items' => [
                        ['icon' => 'shopping-cart', 'title' => 'Fast POS Screen', 'description' => 'Process sales in seconds with barcode scanning and keyboard shortcuts.'],
                        ['icon' => 'boxes', 'title' => 'Smart Inventory', 'description' => 'Real-time stock tracking, low-stock alerts, and multi-branch support.'],
                        ['icon' => 'users', 'title' => 'Customer CRM', 'description' => 'Build loyalty with customer profiles, purchase history, and rewards.'],
                        ['icon' => 'bar-chart', 'title' => 'Insightful Reports', 'description' => 'Get clear insights into sales, profits, and business performance.'],
                        ['icon' => 'store', 'title' => 'Multi-Branch', 'description' => 'Manage multiple locations from one dashboard with branch-wise stock.'],
                        ['icon' => 'credit-card', 'title' => 'Multiple Payments', 'description' => 'Accept cash, cards, mobile wallets, and split payments.'],
                    ],
                ],
                'sort_order' => 2,
            ],
            [
                'section_key' => 'pricing',
                'title' => 'Simple, Transparent Pricing',
                'subtitle' => 'Choose the plan that fits your business. Cancel anytime.',
                'content' => ['show_yearly_toggle' => true],
                'sort_order' => 3,
            ],
            [
                'section_key' => 'testimonials',
                'title' => 'Loved by Businesses Everywhere',
                'subtitle' => 'See what our customers have to say',
                'content' => [
                    'items' => [
                        ['name' => 'Ahmed Khan', 'role' => 'Owner, Khan Mart', 'avatar' => null, 'text' => 'This POS transformed our business. The inventory tracking alone saves us hours every week.'],
                        ['name' => 'Sara Ali', 'role' => 'CEO, Boutique Plus', 'avatar' => null, 'text' => 'Easy to use, beautiful interface, and the customer support is exceptional. Highly recommend!'],
                        ['name' => 'Bilal Hussain', 'role' => 'Manager, Fresh Foods', 'avatar' => null, 'text' => 'Multi-branch support is a game-changer for us. We can finally see everything in one place.'],
                    ],
                ],
                'sort_order' => 4,
            ],
            [
                'section_key' => 'faq',
                'title' => 'Frequently Asked Questions',
                'subtitle' => "Can't find what you're looking for? Contact our support team.",
                'content' => [
                    'items' => [
                        ['question' => 'Do I need to install anything?', 'answer' => 'No, our POS is fully cloud-based. Just open it in any modern browser and you are ready to go.'],
                        ['question' => 'What payment methods can I accept?', 'answer' => 'You can accept cash, credit/debit cards, mobile wallets (JazzCash, Easypaisa), bank transfers, and split payments.'],
                        ['question' => 'Can I use it on a tablet?', 'answer' => 'Yes! Our POS works on desktops, laptops, tablets, and mobile devices.'],
                        ['question' => 'Is my data safe?', 'answer' => 'Absolutely. We use bank-grade encryption, daily backups, and isolated tenant data for maximum security.'],
                        ['question' => 'Can I cancel anytime?', 'answer' => 'Yes. There are no long-term contracts. You can cancel your subscription anytime from your dashboard.'],
                        ['question' => 'Do you support multiple branches?', 'answer' => 'Yes, our Pro and Enterprise plans support multi-branch operations with consolidated reporting.'],
                    ],
                ],
                'sort_order' => 5,
            ],
            [
                'section_key' => 'cta',
                'title' => 'Ready to Transform Your Business?',
                'subtitle' => 'Join thousands of businesses already using our POS to grow faster.',
                'content' => [
                    'cta_text' => 'Start Your Free Trial',
                    'cta_secondary' => 'Talk to Sales',
                ],
                'sort_order' => 6,
            ],
            [
                'section_key' => 'footer',
                'title' => 'POS System',
                'subtitle' => 'Modern Point of Sale for every business.',
                'content' => [
                    'links' => [
                        'product' => [
                            ['label' => 'Features', 'url' => '#features'],
                            ['label' => 'Pricing', 'url' => '#pricing'],
                            ['label' => 'FAQ', 'url' => '#faq'],
                        ],
                        'company' => [
                            ['label' => 'About', 'url' => '/about'],
                            ['label' => 'Contact', 'url' => '/contact'],
                            ['label' => 'Privacy', 'url' => '/privacy'],
                            ['label' => 'Terms', 'url' => '/terms'],
                        ],
                    ],
                    'social' => [
                        ['platform' => 'twitter', 'url' => '#'],
                        ['platform' => 'facebook', 'url' => '#'],
                        ['platform' => 'linkedin', 'url' => '#'],
                    ],
                    'copyright' => '© ' . date('Y') . ' POS System. All rights reserved.',
                ],
                'sort_order' => 7,
            ],
        ];

        foreach ($sections as $section) {
            LandingPageSection::updateOrCreate(
                ['setting_id' => $settings->id, 'section_key' => $section['section_key']],
                array_merge($section, ['is_enabled' => true])
            );
        }
    }
}
