<?php

use App\Controllers\AdminController;
use App\Controllers\AiAssistantController;
use App\Controllers\AuctionController;
use App\Controllers\AuthController;
use App\Controllers\CheckoutController;
use App\Controllers\CatalogController;
use App\Controllers\ChatController;
use App\Controllers\FavoriteController;
use App\Controllers\HomeController;
use App\Controllers\OrderController;
use App\Controllers\ProductController;
use App\Controllers\ProfileController;
use App\Controllers\StoryController;
use App\Controllers\StreamController;
use App\Controllers\WalletController;
use App\Core\Router;

$router = new Router();

$router->get('/', [HomeController::class, 'index']);
$router->get('/catalog/{section}', [CatalogController::class, 'show']);
$router->get('/auctions', [AuctionController::class, 'index']);
$router->post('/auctions/{id}/bid', [AuctionController::class, 'bid']);
$router->get('/product/{id}', [ProductController::class, 'show']);
$router->get('/checkout/success/{id}', [CheckoutController::class, 'success']);
$router->get('/checkout/{id}', [CheckoutController::class, 'show']);
$router->post('/checkout/{id}/pay', [CheckoutController::class, 'pay']);
$router->get('/orders', [OrderController::class, 'index']);
$router->get('/orders/{id}', [OrderController::class, 'show']);
$router->post('/orders/{id}/ship', [OrderController::class, 'ship']);
$router->post('/orders/{id}/delivered', [OrderController::class, 'delivered']);
$router->post('/orders/{id}/confirm', [OrderController::class, 'confirm']);
$router->post('/orders/{id}/dispute', [OrderController::class, 'dispute']);
$router->post('/orders/{id}/return-ship', [OrderController::class, 'returnShip']);
$router->post('/orders/{id}/return-received', [OrderController::class, 'returnReceived']);
$router->post('/orders/{id}/approve-return', [OrderController::class, 'approveReturn']);
$router->post('/orders/{id}/reject-dispute', [OrderController::class, 'rejectDispute']);
$router->get('/wallet', [WalletController::class, 'index']);
$router->post('/wallet/deposit', [WalletController::class, 'deposit']);
$router->post('/wallet/withdraw', [WalletController::class, 'withdraw']);
$router->get('/chat', [ChatController::class, 'index']);
$router->get('/chat/start', [ChatController::class, 'start']);
$router->post('/chat/start', [ChatController::class, 'start']);
$router->get('/chat/{id}', [ChatController::class, 'show']);
$router->post('/chat/{id}/send', [ChatController::class, 'send']);
$router->get('/chat/{id}/poll', [ChatController::class, 'poll']);
$router->post('/favorites/{id}/toggle', [FavoriteController::class, 'toggle']);
$router->post('/ai/chat', [AiAssistantController::class, 'chat']);

$router->get('/login', [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'registerForm']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/auth/google', [AuthController::class, 'googleRedirect']);
$router->get('/auth/google/callback', [AuthController::class, 'googleCallback']);
$router->get('/logout', [AuthController::class, 'logout']);

$router->get('/profile', [ProfileController::class, 'index']);
$router->post('/profile/store', [ProfileController::class, 'store']);
$router->post('/profile/lots/{id}/update', [ProfileController::class, 'updateLot']);
$router->post('/profile/lots/{id}/delete', [ProfileController::class, 'deleteLot']);
$router->post('/profile/avatar', [ProfileController::class, 'avatar']);
$router->post('/profile/personal', [ProfileController::class, 'updatePersonal']);
$router->post('/profile/bio', [ProfileController::class, 'updateBio']);
$router->post('/profile/password', [ProfileController::class, 'updatePassword']);
$router->post('/profile/delete', [ProfileController::class, 'deleteAccount']);
$router->get('/notifications/clear', [ProfileController::class, 'clearNotifications']);

$router->post('/stories', [StoryController::class, 'store']);
$router->post('/stories/{id}/delete', [StoryController::class, 'delete']);

$router->post('/streams/live/start', [StreamController::class, 'startLive']);
$router->post('/streams/live/heartbeat', [StreamController::class, 'heartbeat']);
$router->post('/streams/live/end', [StreamController::class, 'endLive']);
$router->post('/streams/{id}/delete', [StreamController::class, 'delete']);

$router->get('/admin', [AdminController::class, 'index']);
$router->post('/admin/delete/{id}', [AdminController::class, 'delete']);
$router->post('/admin/toggle/{id}', [AdminController::class, 'toggleStatus']);

return $router;
