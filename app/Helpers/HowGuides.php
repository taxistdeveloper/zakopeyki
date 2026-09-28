<?php

namespace App\Helpers;

class HowGuides
{
    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        $guides = [
            'new' => [
                'lead' => 'catalog.new_lead',
                'blocks' => [
                    ['title' => 'catalog.how_buyer', 'steps' => 'catalog.new_buyer_', 'stepsCount' => 9],
                    ['title' => 'catalog.how_seller', 'steps' => 'catalog.new_seller_', 'stepsCount' => 7],
                ],
            ],
            'used' => [
                'lead' => 'catalog.used_lead',
                'blocks' => [
                    ['title' => 'catalog.how_buyer', 'steps' => 'catalog.used_buyer_', 'stepsCount' => 9],
                    ['title' => 'catalog.how_seller', 'steps' => 'catalog.used_seller_', 'stepsCount' => 6],
                    ['callout' => 'catalog.used_note'],
                ],
            ],
            'exchange' => [
                'lead' => 'catalog.exchange_lead',
                'blocks' => [
                    ['title' => 'catalog.exchange_offer_title', 'steps' => 'catalog.exchange_step_', 'stepsCount' => 7],
                    ['title' => 'catalog.exchange_extra_title', 'text' => 'catalog.exchange_extra'],
                ],
            ],
            'free' => [
                'lead' => 'catalog.free_lead',
                'blocks' => [
                    ['title' => 'catalog.free_get_title', 'steps' => 'catalog.free_get_', 'stepsCount' => 5],
                    ['title' => 'catalog.free_give_title', 'steps' => 'catalog.free_give_', 'stepsCount' => 6],
                    ['callout' => 'catalog.free_note'],
                ],
            ],
            'service' => [
                'lead' => 'catalog.service_lead',
                'blocks' => [
                    ['title' => 'catalog.service_find_title', 'steps' => 'catalog.service_find_', 'stepsCount' => 7],
                    ['title' => 'catalog.service_post_title', 'steps' => 'catalog.service_post_', 'stepsCount' => 8],
                    ['callout' => 'catalog.service_note'],
                ],
            ],
            'auctions' => [
                'lead' => 'auctions.guide_lead',
                'kindsTitle' => 'auctions.kinds_intro',
                'kinds' => ['auctions.kind_line_en', 'auctions.kind_line_nl', 'auctions.kind_line_open'],
                'blocks' => [
                    [
                        'title' => 'auctions.before_title',
                        'points' => [
                            ['title' => 'auctions.before_card_title', 'text' => 'auctions.before_card'],
                            ['title' => 'auctions.before_pay_title', 'text' => 'auctions.before_pay'],
                            ['title' => 'auctions.before_refuse_title', 'text' => 'auctions.before_refuse'],
                        ],
                    ],
                    [
                        'title' => 'auctions.english_title',
                        'text' => 'auctions.english_lead',
                        'stepsTitle' => 'auctions.how_join',
                        'steps' => 'auctions.english_',
                        'stepsCount' => 8,
                        'asideTitle' => 'auctions.sniper_title',
                        'aside' => 'auctions.sniper',
                    ],
                    [
                        'title' => 'auctions.win_title',
                        'text' => 'auctions.win',
                        'callout' => 'auctions.win_note',
                    ],
                    [
                        'title' => 'auctions.dutch_title',
                        'text' => 'auctions.dutch_lead',
                        'stepsTitle' => 'auctions.how_join',
                        'steps' => 'auctions.dutch_',
                        'stepsCount' => 8,
                        'asideTitle' => 'auctions.dutch_principle_title',
                        'aside' => 'auctions.dutch_principle',
                    ],
                    [
                        'title' => 'auctions.open_title',
                        'text' => 'auctions.open_lead',
                        'stepsTitle' => 'auctions.how_join',
                        'steps' => 'auctions.open_',
                        'stepsCount' => 9,
                    ],
                    [
                        'title' => 'auctions.open_seller_title',
                        'steps' => 'auctions.open_seller_',
                        'stepsCount' => 7,
                    ],
                ],
            ],
        ];

        return $guides[$id] ?? null;
    }
}
