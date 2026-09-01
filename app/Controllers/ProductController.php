<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;
use App\Models\Favorite;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Review;
use App\Models\SupportTicket;
use App\Services\Digital\DigitalAccessService;
use App\Services\Listing\ListingShippingService;

class ProductController extends Controller
{
    public function show(string $id): void
    {
        $product = (new Product())->findWithSeller((int) $id);
        if (!$product || ($product['type'] ?? '') === 'course') {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('product.not_found')]);
            return;
        }

        $bids = [];
        if ($product['type'] === 'auction') {
            $auction = (new \App\Services\AuctionService())->details((int) $id);
            if ($auction) {
                $product = array_merge($product, $auction);
                $bids = $auction['bids'] ?? [];
            }
        }

        $notifications = [];
        $unread = 0;
        $isFavorite = false;
        $favoriteIds = [];
        if (Auth::check()) {
            $n = new Notification();
            $notifications = $n->forUser(Auth::id());
            $unread = $n->unreadCount(Auth::id());
            $favoriteIds = (new Favorite())->idsForUser(Auth::id());
            $isFavorite = in_array((int) $id, $favoriteIds, true);
        }

        $sellerRating = (new Review())->statsFor((int) $product['user_id']);
        $similar = (new Product())->similar($product, 8);

        $product['view_count'] = (new Product())->recordView((int) $id, (int) $product['user_id']);

        $digitalHasAccess = false;
        if (($product['type'] ?? '') === 'course' && Auth::check()) {
            $digitalHasAccess = (new DigitalAccessService())->resolveViewer((int) $id, (int) Auth::id())['ok'] ?? false;
        }

        $listingShipping = null;
        if (!ProductHelper::isDigitalListing($product)) {
            $shipRow = (new ListingShippingService())->findForProduct((int) $id);
            $listingShipping = (new ListingShippingService())->buyerSummary(
                $shipRow,
                (string) ($product['location'] ?? '')
            );
        }

        $this->view('products/show', [
            'title' => $product['title'],
            'currentNav' => '',
            'item' => $product,
            'bids' => $bids,
            'notifications' => $notifications,
            'unread' => $unread,
            'isFavorite' => $isFavorite,
            'sellerRating' => $sellerRating,
            'similar' => $similar,
            'favoriteIds' => $favoriteIds,
            'search' => '',
            'digitalHasAccess' => $digitalHasAccess,
            'listingShipping' => $listingShipping,
        ]);
    }

    public function whatsapp(string $id): void
    {
        $productId = (int) $id;
        $product = (new Product())->findWithSeller($productId);
        if (!$product) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('product.not_found')]);
            return;
        }

        if (Auth::check() && (int) ($product['user_id'] ?? 0) === (int) Auth::id()) {
            $this->redirect('/product/' . $productId);
            return;
        }

        $digits = ProductHelper::whatsappDigits((string) ($product['whatsapp'] ?? ''));
        if ($digits === null) {
            $_SESSION['error'] = t('product.whatsapp_unavailable');
            $this->redirect('/product/' . $productId);
            return;
        }

        header('Location: https://wa.me/' . $digits, true, 302);
        exit;
    }

    public function report(string $id): void
    {
        Auth::requireLogin();

        $productId = (int) $id;
        $product = (new Product())->findWithSeller($productId);
        if (!$product) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('product.not_found')]);
            return;
        }

        $uid = (int) Auth::id();
        $user = Auth::user();

        if ((int) ($product['user_id'] ?? 0) === $uid) {
            $_SESSION['error'] = t('product.report_own');
            $this->redirect('/product/' . $productId);
            return;
        }

        $reason = strtolower(trim((string) ($_POST['reason'] ?? '')));
        $comment = trim((string) ($_POST['comment'] ?? ''));

        if (!in_array($reason, SupportTicket::REPORT_REASONS, true)) {
            $_SESSION['error'] = t('product.report_reason_required');
            $this->redirect('/product/' . $productId);
            return;
        }

        if ($reason === 'other' && $comment === '') {
            $_SESSION['error'] = t('product.report_comment_required');
            $this->redirect('/product/' . $productId);
            return;
        }

        if (mb_strlen($comment) > 2000) {
            $_SESSION['error'] = t('product.report_comment_long');
            $this->redirect('/product/' . $productId);
            return;
        }

        $tickets = new SupportTicket();
        if ($tickets->hasOpenListingReport($uid, $productId)) {
            $_SESSION['error'] = t('product.report_already');
            $this->redirect('/product/' . $productId);
            return;
        }

        $title = (string) ($product['title'] ?? '');
        $sellerName = (string) ($product['seller_name'] ?? '');
        $sellerId = (int) ($product['user_id'] ?? 0);
        $productUrl = ProductHelper::url('/product/' . $productId);
        $reasonLabel = t('product.report_reason_' . $reason);

        $subject = t('product.report_subject', ['title' => $title]);
        if (mb_strlen($subject) > 200) {
            $subject = mb_substr($subject, 0, 197) . '…';
        }
        $bodyParts = [
            t('product.report_body_reason', ['reason' => $reasonLabel]),
            t('product.report_body_listing', [
                'title' => $title,
                'id' => (string) $productId,
                'url' => $productUrl,
            ]),
            t('product.report_body_seller', [
                'name' => $sellerName !== '' ? $sellerName : (string) $sellerId,
                'id' => (string) $sellerId,
            ]),
        ];
        if ($comment !== '') {
            $bodyParts[] = t('product.report_body_comment', ['comment' => $comment]);
        }
        $body = implode("\n\n", $bodyParts);

        $result = $tickets->createTicket($uid, $subject, $body, 'listing', $productId);
        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] ?? t('product.report_failed');
            $this->redirect('/product/' . $productId);
            return;
        }

        $ticketNumber = (string) ($result['ticket_number'] ?? '');
        $ticketId = (int) ($result['ticket_id'] ?? 0);

        ActivityLogger::info('product.report', 'Жалоба на объявление «' . $title . '»', 'product', $productId, [
            'reason' => $reason,
            'ticket_id' => $ticketId,
            'number' => $ticketNumber,
        ]);

        $notify = new Notification();
        $notify->createFor($uid, t('support.notify_created', ['number' => $ticketNumber]));

        foreach ($tickets->adminUsers() as $admin) {
            if ((int) $admin['id'] === $uid) {
                continue;
            }
            $notify->createFor(
                (int) $admin['id'],
                t('support.notify_admin_listing', [
                    'number' => $ticketNumber,
                    'name' => (string) ($user['name'] ?? ''),
                    'title' => $title,
                ])
            );
        }

        $_SESSION['flash'] = t('product.report_sent', ['number' => $ticketNumber]);
        $this->redirect('/product/' . $productId);
    }
}
