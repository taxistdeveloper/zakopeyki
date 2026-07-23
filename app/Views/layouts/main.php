<?php

use App\Helpers\ProductHelper;

function url(string $path = ''): string
{
    return ProductHelper::url($path);
}
?>
<!DOCTYPE html>
<html lang="<?= \App\Core\Lang::htmlLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Zakopeyki') ?> — zakopeyki.kz</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#EFF6FF',
                            100: '#DBEAFE',
                            200: '#BFDBFE',
                            300: '#93C5FD',
                            400: '#3B82F6',
                            500: '#2563EB',
                            600: '#1D4ED8',
                            700: '#1E3A8A',
                            900: '#172554',
                        },
                        accent: {
                            50: '#FFF7ED',
                            100: '#FFEDD5',
                            400: '#FB923C',
                            500: '#F97316',
                            600: '#EA580C',
                            700: '#C2410C',
                        },
                        gold: {
                            500: '#F59E0B',
                        },
                        ink: {
                            50: '#F8FAFC',
                            100: '#F1F5F9',
                            700: '#334155',
                            800: '#1E293B',
                            900: '#0F172A',
                        }
                    },
                    fontFamily: {
                        sans: ['"DM Sans"', 'system-ui', 'sans-serif'],
                        display: ['Sora', 'system-ui', 'sans-serif'],
                    },
                    boxShadow: {
                        soft: '0 1px 2px rgba(37,99,235,0.06), 0 8px 24px rgba(147,197,253,0.35)',
                        lift: '0 12px 40px rgba(30,58,138,0.14)',
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            transition: background-color 0.3s, color 0.3s;
        }
        .font-display { font-family: Sora, system-ui, sans-serif; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        /* Instagram web stories */
        .story-viewer-shell {
            background: #262626;
        }
        .story-backdrop { display: none !important; }
        .story-brand {
            position: absolute;
            top: 12px;
            left: 16px;
            z-index: 5;
            font-family: Sora, system-ui, sans-serif;
            font-weight: 800;
            font-size: 16px;
            color: #fafafa;
            letter-spacing: -0.03em;
            text-decoration: none;
        }
        .story-brand span { color: #F97316; }
        .story-stage {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            width: 100%;
            height: 100%;
            padding: 0;
            box-sizing: border-box;
        }
        .story-peek { display: none !important; }
        .story-frame-wrap {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .story-frame {
            position: relative;
            /* Почти весь экран по высоте — как Instagram */
            height: 100dvh;
            width: calc(100dvh * 9 / 16);
            max-width: min(480px, calc(100vw - 100px));
            border-radius: 0;
            overflow: hidden;
            background: #000;
        }
        @media (min-width: 721px) {
            .story-frame {
                height: calc(100dvh - 8px);
                width: calc((100dvh - 8px) * 9 / 16);
                max-width: min(480px, calc(100vw - 100px));
                border-radius: 10px;
            }
        }
        .story-nav-btn {
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: none;
            background: #555;
            color: #fff;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 0 2px;
            transition: background .15s;
        }
        .story-nav-btn:hover { background: #6e6e6e; }
        .story-nav-btn.is-hidden { visibility: hidden; pointer-events: none; }
        #story-nav-prev, #stream-nav-prev,
        #story-nav-next, #stream-nav-next {
            position: static;
            transform: none;
            left: auto;
            right: auto;
        }
        .story-close-outer {
            position: absolute;
            top: 10px;
            right: 12px;
            z-index: 5;
            width: 40px;
            height: 40px;
            border: none;
            background: transparent;
            color: #fafafa;
            font-size: 26px;
            cursor: pointer;
            line-height: 1;
        }
        .story-progress-bar {
            height: 2px;
            border-radius: 999px;
            background: rgba(255,255,255,0.35);
            overflow: hidden;
            flex: 1;
        }
        .story-progress-bar > span {
            display: block;
            height: 100%;
            width: 0;
            background: #fff;
            border-radius: inherit;
        }
        .story-text-bg {
            background:
                radial-gradient(ellipse 100% 70% at 50% 30%, rgba(255,255,255,0.2), transparent 55%),
                linear-gradient(165deg, var(--story-c1, #2563EB) 0%, var(--story-c2, #F97316) 45%, #0F172A 100%);
        }
        .story-vignette {
            background: linear-gradient(180deg, rgba(0,0,0,.4) 0%, transparent 18%, transparent 65%, rgba(0,0,0,.5) 100%);
            pointer-events: none;
        }
        #story-viewer-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            background: #efefef;
            color: #262626;
            font-weight: 800;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 0 1.5px #fff;
        }
        #story-emoji-icon {
            font-size: clamp(3.5rem, 9vh, 5.5rem);
            filter: drop-shadow(0 6px 16px rgba(0,0,0,.3));
        }
        #story-caption-center {
            margin-top: 12px;
            font-family: Sora, system-ui, sans-serif;
            font-size: clamp(16px, 2.6vh, 22px);
            font-weight: 700;
            color: #fff;
            text-shadow: 0 2px 12px rgba(0,0,0,.4);
            max-width: 85%;
            line-height: 1.3;
            text-align: center;
        }
        .story-product-card {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            border-radius: 12px;
            padding: 8px 12px 8px 8px;
            text-decoration: none;
            color: #262626;
            box-shadow: 0 4px 20px rgba(0,0,0,.25);
        }
        .story-product-card img,
        .story-product-card .story-product-ph {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .story-reply-bar {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .story-reply-input {
            flex: 1;
            min-width: 0;
            height: 44px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,.6);
            background: transparent;
            color: #fff;
            padding: 0 16px;
            font-size: 14px;
            outline: none;
        }
        .story-reply-input::placeholder { color: rgba(255,255,255,.7); }
        .story-reply-input:focus { border-color: #fff; }
        .story-action-btn {
            width: 40px;
            height: 40px;
            border: none;
            background: transparent;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            padding: 0;
        }
        .story-action-btn.is-liked { color: #ff3040; }
        .story-footer {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 25;
            padding: 0 12px max(14px, env(safe-area-inset-bottom));
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        @media (max-width: 720px) {
            .story-stage { padding: 0; gap: 0; }
            .story-frame {
                width: 100vw !important;
                height: 100dvh !important;
                max-width: 100vw;
                max-height: 100dvh;
                border-radius: 0;
            }
            .story-nav-btn,
            .story-brand { display: none; }
            .story-close-outer { top: max(10px, env(safe-area-inset-top)); }
        }

        .app-shell {
            background:
                radial-gradient(1200px 600px at 10% -10%, rgba(37, 99, 235, 0.12), transparent 55%),
                radial-gradient(900px 500px at 100% 0%, rgba(249, 115, 22, 0.08), transparent 50%),
                linear-gradient(180deg, #F8FAFC 0%, #EFF6FF 100%);
        }
        .dark .app-shell {
            background:
                radial-gradient(1000px 500px at 0% 0%, rgba(37, 99, 235, 0.16), transparent 50%),
                linear-gradient(180deg, #0F172A 0%, #1E293B 100%);
        }
        .glass {
            background: rgba(255,255,255,0.82);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
        .dark .glass {
            background: rgba(30, 41, 59, 0.88);
        }
        .nav-pill-active {
            background: linear-gradient(135deg, #EFF6FF, #DBEAFE);
            color: #1E3A8A;
            box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.28);
        }
        .dark .nav-pill-active {
            background: linear-gradient(135deg, rgba(37,99,235,0.28), rgba(37,99,235,0.1));
            color: #93C5FD;
            box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.3);
        }
        .ui-input {
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .ui-input:focus {
            border-color: #2563EB;
            box-shadow: 0 0 0 3px rgba(147, 197, 253, 0.55);
            outline: none;
        }
        .fade-up {
            /* backwards, а не both: заполненная анимация transform делает элемент
               containing block'ом для position:fixed потомков (модалки сторис) */
            animation: fadeUp .45s ease backwards;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: none; }
        }
        .ai-msg-user {
            background: linear-gradient(135deg, #1E3A8A, #2563EB);
            color: #fff;
            border-radius: 16px 16px 4px 16px;
        }
        .ai-msg-bot {
            background: rgba(255,255,255,0.95);
            border: 1px solid rgba(26,25,22,0.08);
            border-radius: 16px 16px 16px 4px;
        }
        .dark .ai-msg-bot {
            background: rgba(42,40,36,0.95);
            border-color: rgba(255,255,255,0.08);
        }
    </style>
</head>
<body class="app-shell text-ink-900 dark:text-gray-100 flex h-screen overflow-hidden select-none relative">
    <div id="sidebar-overlay" onclick="toggleSidebar()" class="hidden fixed inset-0 bg-ink-900/40 z-40 backdrop-blur-sm"></div>

    <?php \App\Core\View::partial('partials/sidebar', ['currentNav' => $currentNav ?? '']); ?>

    <div id="main-container" class="flex-1 flex flex-col h-full overflow-hidden relative transition-all duration-300 lg:pl-64">
        <?php \App\Core\View::partial('partials/header', [
            'notifications' => $notifications ?? [],
            'unread' => $unread ?? 0,
            'search' => $search ?? '',
        ]); ?>

        <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 pb-28">
            <div class="fade-up max-w-[1400px] mx-auto">
                <?= $content ?>
            </div>
        </main>

    </div>

    <!-- ИИ-помощник: отдельный fixed-слой, не внутри overflow-hidden -->
    <div id="ai-assistant" class="fixed bottom-5 right-5 z-[90] flex flex-col items-end gap-3 pointer-events-none">
        <div id="ai-assistant-panel" class="hidden pointer-events-auto w-[min(calc(100vw-1.5rem),380px)] h-[min(68vh,520px)] glass rounded-2xl shadow-lift border border-ink-900/10 dark:border-white/10 flex flex-col overflow-hidden" role="dialog" aria-label="<?= htmlspecialchars(t('ai.aria')) ?>" aria-hidden="true">
            <div class="px-4 py-3 border-b border-ink-900/10 dark:border-white/10 flex items-center justify-between shrink-0 bg-gradient-to-r from-brand-100/90 to-transparent dark:from-brand-500/15">
                <div class="min-w-0">
                    <p class="font-display font-bold text-sm text-ink-900 dark:text-white truncate"><?= htmlspecialchars(t('ai.title')) ?></p>
                    <p class="text-[11px] text-ink-700/70 dark:text-gray-400 truncate"><?= htmlspecialchars(t('ai.subtitle')) ?></p>
                </div>
                <button type="button" id="ai-assistant-close" class="shrink-0 w-8 h-8 rounded-xl hover:bg-ink-900/5 dark:hover:bg-white/10 text-ink-700 dark:text-gray-300 cursor-pointer" aria-label="<?= htmlspecialchars(t('ai.close')) ?>">✕</button>
            </div>
            <div id="ai-chat-messages" class="flex-1 overflow-y-auto p-3 space-y-3 text-sm select-text"></div>
            <div id="ai-chat-suggestions" class="px-3 pb-2 flex flex-wrap gap-1.5 shrink-0"></div>
            <form id="ai-chat-form" class="p-3 border-t border-ink-900/10 dark:border-white/10 flex gap-2 shrink-0">
                <input id="ai-chat-input" type="text" maxlength="500" placeholder="<?= htmlspecialchars(t('ai.placeholder')) ?>" autocomplete="off"
                    class="ui-input flex-1 min-w-0 rounded-xl border border-ink-900/10 dark:border-white/10 bg-white/80 dark:bg-ink-900/40 px-3 py-2.5 text-sm text-ink-900 dark:text-gray-100 placeholder:text-ink-700/40">
                <button type="submit" class="shrink-0 rounded-xl bg-accent-500 hover:bg-accent-600 text-white font-display font-semibold text-sm px-3.5 py-2.5 transition cursor-pointer">
                    <?= htmlspecialchars(t('ai.send')) ?>
                </button>
            </form>
        </div>

        <button type="button" id="ai-assistant-toggle" class="pointer-events-auto flex items-center gap-2 bg-ink-900 hover:bg-ink-800 text-white pl-3.5 pr-4 py-3 rounded-2xl shadow-lift border border-white/10 cursor-pointer transition hover:-translate-y-0.5" aria-expanded="false" aria-controls="ai-assistant-panel">
            <span class="text-xl leading-none" aria-hidden="true">🤖</span>
            <span class="font-display font-semibold text-xs uppercase tracking-wider"><?= htmlspecialchars(t('ai.toggle')) ?></span>
        </button>
    </div>

    <script>
        window.__isLoggedIn = <?= \App\Core\Auth::check() ? 'true' : 'false' ?>;
        window.__loginUrl = <?= json_encode(url('/login')) ?>;
        window.__favoritesToggleBase = <?= json_encode(rtrim(url('/favorites'), '/') . '/') ?>;
        window.__aiChatUrl = <?= json_encode(url('/ai/chat')) ?>;
        window.__lang = <?= json_encode(\App\Core\Lang::current()) ?>;
        window.__i18n = <?= json_encode(\App\Core\Lang::forJs([
            'ai.welcome', 'ai.suggest_free', 'ai.suggest_exchange', 'ai.suggest_services', 'ai.suggest_sell',
            'ai.suggest_auctions', 'ai.msg_free', 'ai.msg_exchange', 'ai.msg_services', 'ai.msg_sell',
            'ai.msg_auctions', 'ai.error_reply', 'ai.error_network', 'js.now',
            'js.live_host', 'js.login_to_stream', 'js.stream_fail', 'js.stream_desc',
            'js.you', 'js.stream_error', 'card.favorite', 'card.unfavorite',
            'home.story_link_copied',
            'header.city', 'header.city_choose', 'header.city_detect', 'header.city_detecting', 'header.city_denied',
        ]), JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="<?= url('public/assets/js/app.js') ?>"></script>
</body>
</html>
