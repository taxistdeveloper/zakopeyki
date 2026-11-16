<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\AboutDocumentsHelper;
use App\Helpers\ProductHelper;

/**
 * Тексты для нативных экранов приложения: «О сервисе», оферта, политики, правила размещения.
 * Источник — те же языковые строки, что у страниц сайта (about/index, legal/offer, legal/document,
 * partials/listing-rules-modal), поэтому тексты всегда совпадают с сайтом.
 */
final class PagesApiController extends BaseMobileController
{
    /** @var array<string, list<string>> разделы юридических документов (как в HomeController) */
    private const LEGAL = [
        'offer' => ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9', 's10'],
        'privacy' => ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9'],
        'data_policy' => ['s1', 's2', 's3', 's4', 's5', 's6', 's7'],
    ];

    /** GET /pages/{slug}: about | offer | privacy | data-policy | listing-rules */
    public function show(string $slug): void
    {
        $slug = str_replace('-', '_', $slug);
        if ($slug === 'about') {
            $this->ok(['page' => $this->about()]);
        }
        if ($slug === 'listing_rules') {
            $this->ok(['page' => $this->listingRules()]);
        }
        if (!isset(self::LEGAL[$slug])) {
            $this->fail('not_found', 404);
        }
        $sections = [];
        foreach (self::LEGAL[$slug] as $id) {
            $sections[] = ['title' => t($slug . '.' . $id . '_title'), 'body' => t($slug . '.' . $id . '_body')];
        }
        $sections[] = ['title' => t($slug . '.contacts_title'), 'body' => t($slug . '.contacts_body')];
        $this->ok(['page' => [
            'title' => t($slug . '.title'),
            'lead' => t($slug . '.lead'),
            'effective' => t($slug . '.effective'),
            'sections' => $sections,
            'documents' => [],
            'contact_email' => 'support@zakopeyki.kz',
        ]]);
    }

    /** @return array<string, mixed> */
    private function about(): array
    {
        $docs = [];
        foreach (AboutDocumentsHelper::all() as $d) {
            $docs[] = [
                'title' => (string) ($d['title'] ?? $d['file'] ?? ''),
                'url' => ProductHelper::absoluteUrl('/about/document/' . rawurlencode((string) ($d['slug'] ?? $d['file'] ?? ''))),
            ];
        }

        return [
            'title' => t('about.title'),
            'lead' => t('about.lead'),
            'effective' => null,
            'sections' => [
                ['title' => t('about.innov_title'), 'body' => t('about.innov_text')],
                ['title' => t('about.security_title'), 'body' => implode("\n\n", [
                    t('about.security_intro'), '• ' . t('about.security_1'), '• ' . t('about.security_2'), '• ' . t('about.security_3'),
                ])],
                ['title' => t('about.care_title'), 'body' => t('about.care_text')],
                ['title' => t('about.official_title'), 'body' => '• ' . t('about.official_1') . "\n• " . t('about.official_2')],
            ],
            'tagline' => t('about.tagline'),
            'documents_title' => t('about.docs_title'),
            'documents' => $docs,
            'contact_email' => 'support@zakopeyki.kz',
        ];
    }

    /** @return array<string, mixed> */
    private function listingRules(): array
    {
        $items = [];
        for ($i = 1; $i <= 9; $i++) {
            $items[] = '• ' . t('listing_rules.item_' . $i);
        }

        return [
            'title' => t('listing_rules.title'),
            'lead' => t('listing_rules.lead'),
            'effective' => null,
            'sections' => [
                ['title' => t('listing_rules.forbidden'), 'body' => implode("\n", $items)],
                ['title' => '', 'body' => t('listing_rules.warning')],
            ],
            'documents' => [],
            'contact_email' => 'support@zakopeyki.kz',
        ];
    }
}
