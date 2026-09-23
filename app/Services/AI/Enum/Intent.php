<?php

declare(strict_types=1);

namespace App\Services\AI\Enum;

enum Intent: string
{
    case SearchProduct = 'SEARCH_PRODUCT';
    case SearchCategory = 'SEARCH_CATEGORY';
    case CreateListing = 'CREATE_LISTING';
    case EditListing = 'EDIT_LISTING';
    case AnalyzeListing = 'ANALYZE_LISTING';
    case PriceRecommendation = 'PRICE_RECOMMENDATION';
    case ImageAnalysis = 'IMAGE_ANALYSIS';
    case ProductComparison = 'PRODUCT_COMPARISON';
    case OrderStatus = 'ORDER_STATUS';
    case DealStatus = 'DEAL_STATUS';
    case DeliveryStatus = 'DELIVERY_STATUS';
    case PaymentStatus = 'PAYMENT_STATUS';
    case ReturnRequest = 'RETURN_REQUEST';
    case DisputeRequest = 'DISPUTE_REQUEST';
    case SellerAnalytics = 'SELLER_ANALYTICS';
    case SalesRecommendation = 'SALES_RECOMMENDATION';
    case DemandAnalysis = 'DEMAND_ANALYSIS';
    case UserProfile = 'USER_PROFILE';
    case Favorites = 'FAVORITES';
    case Recommendations = 'RECOMMENDATIONS';
    case Support = 'SUPPORT';
    case GeneralQuestion = 'GENERAL_QUESTION';
    case Greeting = 'GREETING';
    case HumanEscalate = 'HUMAN_ESCALATE';
    case Unknown = 'UNKNOWN';

    public static function tryFromLoose(string $value): self
    {
        $normalized = strtoupper(trim($value));
        // Legacy intents from IntentClassifier
        return match ($normalized) {
            'FAQ_QUERY' => self::Support,
            'ACTION_QUERY' => self::OrderStatus,
            'CATALOG_SEARCH' => self::SearchProduct,
            'GREETING' => self::Greeting,
            'HUMAN_ESCALATE' => self::HumanEscalate,
            default => self::tryFrom($normalized) ?? self::Unknown,
        };
    }
}
