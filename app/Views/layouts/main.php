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
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','GTM-5ZJNMVQ2');</script>
    <!-- End Google Tag Manager -->
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-385N8EWS73"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', 'G-385N8EWS73');
    </script>
    <!-- Yandex.Metrika counter -->
    <script type="text/javascript">
        (function(m,e,t,r,i,k,a){
            m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
            m[i].l=1*new Date();
            for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
            k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
        })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id=111029736', 'ym');

        ym(111029736, 'init', {ssr:true, webvisor:true, clickmap:true, ecommerce:"dataLayer", referrer: document.referrer, url: location.href, accurateTrackBounce:true, trackLinks:true});
    </script>
    <noscript><div><img src="https://mc.yandex.ru/watch/111029736" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
    <!-- /Yandex.Metrika counter -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= \App\Core\Csrf::meta() ?>
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

        /* Custom scrollbar */
        html {
            scrollbar-width: thin;
            scrollbar-color: #93C5FD transparent;
        }
        html.dark {
            scrollbar-color: #1E3A8A transparent;
        }
        *::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        *::-webkit-scrollbar-track {
            background: transparent;
        }
        *::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #93C5FD, #3B82F6);
            border-radius: 999px;
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        *::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #60A5FA, #2563EB);
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        *::-webkit-scrollbar-corner {
            background: transparent;
        }
        html.dark *::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #1E3A8A, #2563EB);
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        html.dark *::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #2563EB, #3B82F6);
            border: 2px solid transparent;
            background-clip: padding-box;
        }
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
        /* ===== Live shop v2 — face-safe dock + rail ===== */
        #live-shop-ui {
            --live-gold: #C9A227;
            --live-gold-hi: #E4C65A;
            --live-ink: #0c0a09;
            --live-glass: rgba(10, 8, 6, 0.42);
            --live-glass-strong: rgba(10, 8, 6, 0.58);
            --live-line: rgba(255,255,255,0.14);
            --live-danger: #ef4444;
            font-family: 'DM Sans', system-ui, sans-serif;
        }
        #live-shop-ui .sr-only {
            position: absolute; width: 1px; height: 1px;
            padding: 0; margin: -1px; overflow: hidden;
            clip: rect(0,0,0,0); white-space: nowrap; border: 0;
        }
        .live-v2-scrim { position: absolute; left: 0; right: 0; z-index: 0; pointer-events: none; }
        .live-v2-scrim--top {
            top: 0; height: 26%;
            background: linear-gradient(180deg, rgba(0,0,0,0.55) 0%, rgba(0,0,0,0.18) 55%, transparent 100%);
        }
        .live-v2-scrim--bottom {
            bottom: 0; height: 38%;
            background: linear-gradient(0deg, rgba(0,0,0,0.62) 0%, rgba(0,0,0,0.22) 45%, transparent 100%);
        }

        .live-v2-top {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px 10px;
            padding: max(10px, env(safe-area-inset-top, 0px)) max(12px, env(safe-area-inset-right, 0px)) 4px max(12px, env(safe-area-inset-left, 0px));
            align-items: start;
        }
        .live-v2-host {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            padding: 5px 8px 5px 5px;
            border-radius: 999px;
            background: var(--live-glass);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid var(--live-line);
            box-shadow: 0 6px 18px rgba(0,0,0,0.22);
        }
        .live-v2-avatar {
            width: 34px; height: 34px; border-radius: 999px;
            background: #fff; color: #262626;
            font-weight: 900; font-size: 13px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 0 0 2px rgba(255,255,255,0.85);
        }
        .live-v2-host-meta { min-width: 0; flex: 1; }
        .live-v2-host-row { display: flex; align-items: center; gap: 4px; min-width: 0; }
        .live-v2-host-name {
            font-family: Sora, system-ui, sans-serif;
            font-size: 12px; font-weight: 700;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            text-shadow: 0 1px 2px rgba(0,0,0,0.35);
        }
        .live-v2-star { color: var(--live-gold); flex-shrink: 0; }
        .live-v2-follow {
            flex-shrink: 0;
            margin-left: 2px;
            border: 0; cursor: pointer;
            font-size: 10px; font-weight: 800;
            padding: 3px 8px; border-radius: 999px;
            background: var(--live-gold); color: var(--live-ink);
        }
        .live-v2-followers {
            margin: 0; font-size: 9px; color: rgba(255,255,255,0.72);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .live-v2-live-block {
            display: flex; flex-direction: column; align-items: flex-end;
            gap: 2px; flex-shrink: 0; padding-right: 2px;
        }
        .live-v2-live-badge {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 9px; font-weight: 900; letter-spacing: 0.04em;
            background: var(--live-danger); color: #fff;
            padding: 2px 7px; border-radius: 6px;
            box-shadow: 0 2px 8px rgba(239,68,68,0.45);
        }
        .live-v2-live-badge i {
            width: 5px; height: 5px; border-radius: 999px; background: #fff;
            display: inline-block;
            animation: livePulse 1.2s ease-in-out infinite;
        }
        @keyframes livePulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.45; transform: scale(0.85); }
        }
        .live-v2-timer {
            font-size: 10px; font-weight: 700; font-variant-numeric: tabular-nums;
            color: rgba(255,255,255,0.9); text-shadow: 0 1px 2px rgba(0,0,0,0.4);
        }
        .live-v2-top-actions { display: flex; align-items: center; gap: 6px; }
        .live-v2-chip {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 6px 10px; border-radius: 999px;
            background: var(--live-glass); backdrop-filter: blur(12px);
            border: 1px solid var(--live-line);
            font-size: 11px; font-weight: 700;
        }
        .live-v2-icon-round {
            width: 32px; height: 32px; border-radius: 999px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--live-glass); backdrop-filter: blur(12px);
            border: 1px solid var(--live-line);
            color: #fff; cursor: pointer; padding: 0;
        }
        .live-v2-support {
            grid-column: 1 / -1;
            display: inline-flex; align-items: center; gap: 8px;
            max-width: 78%;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(0,0,0,0.28);
            border: 1px solid rgba(255,255,255,0.08);
            backdrop-filter: blur(8px);
            width: fit-content;
        }
        .live-v2-support-label {
            font-size: 8px; font-weight: 800; letter-spacing: 0.06em;
            text-transform: uppercase; color: var(--live-gold-hi); flex-shrink: 0;
        }
        .live-v2-support-list {
            font-size: 10px; color: rgba(255,255,255,0.78);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .live-v2-stage { position: relative; }
        .live-v2-chat {
            position: absolute;
            left: 12px; bottom: 8px;
            width: min(58%, 230px);
            display: flex; flex-direction: column; justify-content: flex-end;
            gap: 6px;
            max-height: 30vh;
        }
        .live-shop-comments {
            display: flex; flex-direction: column; gap: 5px;
            overflow: hidden; max-height: 30vh;
        }
        .live-shop-comments .live-cmt {
            display: block; max-width: 100%;
            padding: 6px 10px; border-radius: 14px 14px 14px 4px;
            background: rgba(0,0,0,0.36);
            backdrop-filter: blur(10px);
            color: #fff; font-size: 11px; line-height: 1.35;
            text-align: left;
            animation: liveCmtIn .28s cubic-bezier(.2,.8,.2,1);
            border: 1px solid rgba(255,255,255,0.06);
        }
        .live-shop-comments .live-cmt strong { font-weight: 700; margin-right: 4px; color: #fff; }
        .live-shop-comments .live-cmt.is-host {
            background: rgba(201, 162, 39, 0.22);
            border-color: rgba(201, 162, 39, 0.35);
        }
        .live-shop-comments .live-cmt.is-host .host-tag {
            font-size: 9px; font-weight: 800; text-transform: uppercase;
            color: var(--live-gold-hi); margin-right: 4px;
        }
        @keyframes liveCmtIn {
            from { opacity: 0; transform: translateY(10px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .live-shop-toast {
            background: linear-gradient(135deg, rgba(31,77,58,0.9), rgba(201,162,39,0.75));
            backdrop-filter: blur(8px);
            border-radius: 12px;
            padding: 8px 10px;
            font-size: 11px; font-weight: 650; line-height: 1.3;
            border: 1px solid rgba(255,255,255,0.12);
            animation: liveCmtIn .25s ease;
        }

        .live-v2-deal-col {
            position: absolute;
            right: 58px; bottom: 6px;
            width: min(46%, 168px);
            display: flex; flex-direction: column; gap: 8px;
            align-items: stretch;
            pointer-events: none;
        }
        .live-v2-deal-col > * { pointer-events: auto; }
        .live-v2-deal {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            margin-bottom: 8px;
            padding: 8px 8px 8px 8px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(18,14,10,0.78), rgba(28,22,14,0.72));
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(201,162,39,0.4);
            box-shadow:
                0 10px 28px rgba(0,0,0,0.32),
                inset 0 1px 0 rgba(255,255,255,0.08);
            cursor: pointer;
            overflow: hidden;
            animation: liveFeatIn .4s cubic-bezier(.2,.8,.2,1);
        }
        .live-v2-deal::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, var(--live-gold-hi), var(--live-gold));
            border-radius: 3px 0 0 3px;
        }
        .live-v2-deal-img {
            width: 52px; height: 52px; border-radius: 14px;
            background: rgba(255,255,255,0.1);
            flex-shrink: 0;
            position: relative; z-index: 1;
            box-shadow: 0 0 0 1px rgba(255,255,255,0.1);
        }
        .live-v2-deal-body {
            min-width: 0; flex: 1;
            position: relative; z-index: 1;
        }
        .live-v2-deal-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 8px; font-weight: 800; letter-spacing: 0.08em;
            text-transform: uppercase; color: var(--live-gold-hi);
        }
        .live-v2-deal-title {
            margin: 2px 0 0; font-size: 12px; font-weight: 700; line-height: 1.25;
            color: #fff;
            display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden;
        }
        .live-v2-deal-price {
            margin: 3px 0 0; display: flex; align-items: baseline; gap: 5px;
        }
        .live-v2-deal-price #live-shop-feat-price {
            font-family: Sora, system-ui, sans-serif;
            font-size: 14px; font-weight: 800; color: var(--live-gold-hi);
        }
        .live-v2-deal-price #live-shop-feat-old {
            font-size: 10px; color: rgba(255,255,255,0.4); text-decoration: line-through;
        }
        .live-v2-deal-stock { margin-top: 3px; font-size: 8px; color: rgba(255,255,255,0.65); }
        .live-v2-deal-stock-track {
            height: 3px; border-radius: 999px; background: rgba(255,255,255,0.14);
            margin-top: 2px; overflow: hidden; max-width: 90px;
        }
        .live-v2-deal-stock-track > div {
            height: 100%; border-radius: inherit;
            background: linear-gradient(90deg, var(--live-gold), var(--live-gold-hi));
        }
        .live-v2-deal-buy {
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin: 0;
            border: 0; cursor: pointer;
            border-radius: 999px;
            padding: 10px 14px;
            font-family: Sora, system-ui, sans-serif;
            font-size: 11px; font-weight: 800;
            background: linear-gradient(135deg, var(--live-gold-hi), var(--live-gold));
            color: var(--live-ink);
            position: relative; z-index: 1;
            box-shadow: 0 4px 14px rgba(201,162,39,0.35);
            transition: transform .15s ease, filter .15s ease;
            white-space: nowrap;
        }
        .live-v2-deal-buy:hover { filter: brightness(1.05); }
        .live-v2-deal-buy:active { transform: scale(0.97); }
        @keyframes liveFeatIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .live-v2-give {
            padding: 9px;
            border-radius: 16px;
            background: linear-gradient(160deg, rgba(31,77,58,0.72), rgba(12,10,8,0.7));
            backdrop-filter: blur(12px);
            border: 1px solid rgba(201,162,39,0.28);
            box-shadow: 0 8px 20px rgba(0,0,0,0.28);
            animation: liveFeatIn .4s ease;
        }
        .live-v2-give-head {
            display: flex; align-items: center; justify-content: space-between; gap: 6px;
        }
        .live-v2-give-tag {
            font-size: 8px; font-weight: 800; letter-spacing: 0.06em;
            text-transform: uppercase; color: var(--live-gold-hi);
        }
        .live-v2-give-count { font-size: 9px; color: rgba(255,255,255,0.65); }
        .live-v2-give-title {
            margin: 4px 0 0; font-size: 11px; font-weight: 700; line-height: 1.25;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .live-v2-give-track {
            height: 4px; border-radius: 999px; background: rgba(255,255,255,0.14);
            margin-top: 8px; overflow: hidden;
        }
        .live-v2-give-track > div {
            height: 100%; border-radius: inherit;
            background: linear-gradient(90deg, #1F4D3A, var(--live-gold));
            transition: width .35s ease;
        }
        .live-v2-give-foot {
            margin-top: 7px; display: flex; align-items: center; justify-content: space-between; gap: 6px;
            font-size: 9px; color: rgba(255,255,255,0.65);
        }
        .live-v2-give-btn {
            border: 0; cursor: pointer;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 10px; font-weight: 800;
            background: var(--live-gold); color: var(--live-ink);
        }

        .live-v2-rail {
            position: absolute;
            right: max(6px, env(safe-area-inset-right, 0px));
            bottom: 4px;
            display: flex; flex-direction: column; align-items: center;
            gap: 10px;
            width: 46px;
        }
        .live-v2-rail-btn {
            display: flex; flex-direction: column; align-items: center; gap: 2px;
            background: transparent; border: 0; color: #fff; cursor: pointer;
            text-decoration: none; padding: 0;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.45));
        }
        .live-v2-rail-ico {
            position: relative;
            width: 44px; height: 44px; border-radius: 999px;
            display: flex; align-items: center; justify-content: center;
            background: var(--live-glass);
            backdrop-filter: blur(12px);
            border: 1px solid var(--live-line);
            transition: transform .15s ease, background .15s ease;
        }
        .live-v2-rail-btn:active .live-v2-rail-ico { transform: scale(0.92); }
        .live-v2-rail-btn.is-active .live-v2-rail-ico {
            background: rgba(201,162,39,0.35);
            border-color: rgba(201,162,39,0.55);
        }
        .live-v2-rail-btn.opacity-50 { opacity: 0.45; pointer-events: none; }
        .live-v2-rail-btn--heart .live-v2-rail-ico {
            background: linear-gradient(160deg, rgba(239,68,68,0.55), rgba(10,8,6,0.45));
            border-color: rgba(252,165,165,0.35);
        }
        .live-v2-rail-count, .live-v2-rail-label {
            font-size: 9px; font-weight: 800; line-height: 1.1;
            text-shadow: 0 1px 2px rgba(0,0,0,0.5);
        }
        .live-v2-rail-badge {
            position: absolute; top: -2px; right: -2px;
            min-width: 16px; height: 16px; padding: 0 4px;
            border-radius: 999px;
            background: var(--live-gold); color: var(--live-ink);
            font-size: 9px; font-weight: 900;
            display: flex; align-items: center; justify-content: center;
            border: 1.5px solid rgba(0,0,0,0.25);
        }
        .live-shop-hearts {
            position: absolute; right: 2px; bottom: 52px;
            width: 40px; height: 150px; pointer-events: none;
        }
        .live-shop-hearts .h {
            position: absolute; bottom: 0; right: 6px;
            font-size: 18px;
            animation: liveHeartUp 1.45s ease-out forwards;
            pointer-events: none;
        }
        @keyframes liveHeartUp {
            0% { opacity: 0; transform: translateY(0) scale(.6); }
            15% { opacity: 1; }
            100% { opacity: 0; transform: translateY(-150px) translateX(-10px) scale(1.25); }
        }

        .live-v2-dock { padding: 0 max(12px, env(safe-area-inset-right, 0px)) max(10px, env(safe-area-inset-bottom, 0px)) max(12px, env(safe-area-inset-left, 0px)); }
        .live-v2-shelf {
            margin-bottom: 8px;
            padding: 10px;
            border-radius: 18px;
            background: var(--live-glass-strong);
            backdrop-filter: blur(16px);
            border: 1px solid var(--live-line);
            box-shadow: 0 10px 28px rgba(0,0,0,0.3);
            animation: liveShelfIn .28s ease;
        }
        .live-v2-shelf.is-open { display: block !important; }
        @keyframes liveShelfIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .live-v2-shelf-head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 8px;
        }
        .live-v2-shelf-head p {
            margin: 0; font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.9);
        }
        .live-v2-shelf-close {
            border: 0; background: transparent; color: var(--live-gold-hi);
            font-size: 10px; font-weight: 700; cursor: pointer; padding: 0;
        }
        .live-shop-shelf {
            display: flex; gap: 8px; overflow-x: auto;
            scrollbar-width: none; -ms-overflow-style: none;
            padding-bottom: 2px;
        }
        .live-shop-shelf::-webkit-scrollbar { display: none; }
        .live-shop-shelf-item {
            flex: 0 0 auto; width: 72px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 14px; overflow: hidden; color: #fff;
        }
        .live-shop-shelf-item a { color: inherit; text-decoration: none; display: block; }
        .live-shop-shelf-item img, .live-shop-shelf-item .ph {
            width: 100%; height: 56px; object-fit: cover; display: block;
            background: rgba(255,255,255,0.08);
        }
        .live-shop-shelf-item .ph {
            display: flex; align-items: center; justify-content: center;
            font-size: 10px; color: rgba(255,255,255,0.5);
        }
        .live-shop-shelf-item .pr {
            display: block; padding: 4px 5px 5px;
            font-size: 10px; font-weight: 800; color: var(--live-gold-hi);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .live-shop-shelf-item.is-feat {
            border-color: rgba(201,162,39,0.75);
            box-shadow: 0 0 0 1px rgba(201,162,39,0.35);
        }
        .live-v2-composer {
            display: flex; align-items: center; gap: 8px;
        }
        .live-v2-input {
            flex: 1; min-width: 0;
            display: flex; align-items: center;
            padding: 0 14px;
            height: 42px;
            border-radius: 999px;
            background: var(--live-glass);
            backdrop-filter: blur(14px);
            border: 1px solid var(--live-line);
        }
        .live-v2-input input {
            width: 100%; background: transparent; border: 0; outline: none;
            color: #fff; font-size: 13px;
        }
        .live-v2-input input::placeholder { color: rgba(255,255,255,0.45); }
        .live-v2-end {
            height: 42px; padding: 0 14px; border-radius: 999px;
            border: 1.5px solid rgba(239,68,68,0.55);
            background: rgba(255,255,255,0.95); color: #dc2626;
            font-size: 10px; font-weight: 900; text-transform: uppercase;
            letter-spacing: 0.02em; cursor: pointer; flex-shrink: 0;
            white-space: nowrap;
        }

        /* Product sheet v2 */
        .live-v2-sheet {
            position: relative;
            background: linear-gradient(180deg, #1a1714 0%, #12100e 100%);
            color: #fff;
            border-radius: 24px 24px 0 0;
            padding: 12px 16px max(20px, env(safe-area-inset-bottom, 0px));
            border-top: 1px solid rgba(201,162,39,0.25);
            box-shadow: 0 -12px 40px rgba(0,0,0,0.45);
            animation: liveShelfIn .3s ease;
            max-height: min(72%, calc(100dvh - 40px));
        }
        .live-v2-sheet-handle {
            width: 40px; height: 4px; border-radius: 999px;
            background: rgba(255,255,255,0.22); margin: 0 auto 14px;
        }
        .live-v2-sheet-row { display: flex; gap: 14px; }
        .live-v2-sheet-img {
            width: 96px; height: 96px; border-radius: 18px;
            background: rgba(255,255,255,0.08); flex-shrink: 0;
            box-shadow: 0 0 0 1px rgba(255,255,255,0.08);
        }
        .live-v2-sheet-title {
            margin: 0; font-family: Sora, system-ui, sans-serif;
            font-size: 15px; font-weight: 700; line-height: 1.3;
            display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
        }
        .live-v2-sheet-price {
            margin: 10px 0 0;
            font-family: Sora, system-ui, sans-serif;
            font-size: 20px; font-weight: 800; color: var(--live-gold-hi, #E4C65A);
        }
        .live-v2-sheet-hint {
            margin: 14px 0 0; font-size: 11px; color: rgba(255,255,255,0.5);
        }
        .live-v2-sheet-actions {
            margin-top: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
        }
        .live-v2-sheet-secondary {
            height: 46px; border-radius: 16px; cursor: pointer;
            background: rgba(255,255,255,0.08); color: #fff;
            border: 1px solid rgba(255,255,255,0.12);
            font-size: 13px; font-weight: 700;
        }
        .live-v2-sheet-primary {
            height: 46px; border-radius: 16px; cursor: pointer; border: 0;
            background: linear-gradient(135deg, #E4C65A, #C9A227);
            color: #0c0a09; font-size: 13px; font-weight: 800;
            font-family: Sora, system-ui, sans-serif;
        }
        .live-v2-sheet-back {
            margin-top: 8px; width: 100%; height: 40px; border-radius: 14px;
            background: transparent; color: rgba(255,255,255,0.75);
            border: 1px solid rgba(255,255,255,0.1);
            font-size: 12px; font-weight: 600; cursor: pointer;
        }
        .live-v2-sheet-status {
            margin-top: 10px; text-align: center;
            font-size: 12px; font-weight: 700; color: #6ee7b7;
        }

        .live-v2-waiting {
            background:
                radial-gradient(ellipse at 30% 20%, rgba(201,162,39,0.28), transparent 45%),
                radial-gradient(ellipse at 80% 80%, rgba(31,77,58,0.45), transparent 50%),
                linear-gradient(160deg, #3a1a12 0%, #1a0f0c 45%, #0c0a09 100%);
        }
        .live-v2-waiting-avatar {
            width: 88px; height: 88px; border-radius: 999px;
            background: rgba(255,255,255,0.12);
            border: 2px solid rgba(201,162,39,0.45);
            display: flex; align-items: center; justify-content: center;
            font-size: 32px; font-weight: 900;
            box-shadow: 0 12px 40px rgba(0,0,0,0.35);
            animation: liveWaitPulse 2.4s ease-in-out infinite;
        }
        @keyframes liveWaitPulse {
            0%, 100% { transform: scale(1); box-shadow: 0 12px 40px rgba(0,0,0,0.35); }
            50% { transform: scale(1.04); box-shadow: 0 16px 48px rgba(201,162,39,0.22); }
        }

        /* ===== Live shop v2 — responsive ===== */
        #live-shop-ui,
        #live-product-sheet {
            -webkit-tap-highlight-color: transparent;
        }
        #live-shop-ui {
            min-height: 0;
            height: 100%;
            width: 100%;
            box-sizing: border-box;
        }
        .live-v2-stage {
            min-height: 0;
            flex: 1 1 auto;
        }
        .live-v2-chat {
            left: max(10px, env(safe-area-inset-left, 0px));
        }
        .live-v2-deal-col {
            max-width: calc(100% - 64px);
        }
        .live-v2-input input,
        .live-v2-composer,
        .live-v2-rail-btn {
            touch-action: manipulation;
        }
        #stream-live-unmute {
            bottom: max(8rem, calc(env(safe-area-inset-bottom, 0px) + 7.5rem));
            max-width: calc(100% - 2rem);
        }

        /* Narrow phones */
        @media (max-width: 380px) {
            .live-v2-host { gap: 6px; padding: 4px 6px 4px 4px; }
            .live-v2-avatar { width: 30px; height: 30px; font-size: 12px; }
            .live-v2-host-name { font-size: 11px; }
            .live-v2-followers { display: none; }
            .live-v2-support { max-width: 100%; }
            .live-v2-chip { padding: 5px 8px; font-size: 10px; }
            .live-v2-icon-round { width: 30px; height: 30px; }
            .live-v2-rail { width: 40px; gap: 8px; }
            .live-v2-rail-ico { width: 38px; height: 38px; }
            .live-v2-rail-label { font-size: 8px; }
            .live-v2-deal-col {
                right: 50px;
                width: min(52%, 148px);
            }
            .live-v2-deal { padding: 6px 6px 6px 8px; border-radius: 14px; gap: 8px; margin-bottom: 6px; }
            .live-v2-deal-img { width: 44px; height: 44px; border-radius: 12px; }
            .live-v2-deal-title { font-size: 11px; }
            .live-v2-deal-price #live-shop-feat-price { font-size: 12px; }
            .live-v2-deal-buy { padding: 8px 10px; font-size: 10px; }
            .live-v2-deal-buy svg { display: none; }
            .live-v2-chat { width: min(54%, 190px); max-height: 24vh; }
            .live-shop-comments .live-cmt { font-size: 10px; padding: 5px 8px; }
            .live-v2-input { height: 40px; }
            .live-v2-end { height: 40px; padding: 0 10px; font-size: 9px; }
            .live-shop-shelf-item { width: 64px; }
            .live-v2-sheet { padding: 12px 14px max(16px, env(safe-area-inset-bottom, 0px)); }
            .live-v2-sheet-img { width: 80px; height: 80px; border-radius: 14px; }
            .live-v2-sheet-title { font-size: 14px; }
            .live-v2-sheet-price { font-size: 18px; }
            .live-v2-sheet-actions { grid-template-columns: 1fr; }
        }

        /* Small / standard phones */
        @media (max-width: 480px) {
            .live-v2-deal-col { bottom: 2px; }
            .live-v2-support { max-width: 92%; }
            .live-v2-composer { gap: 6px; }
            .live-v2-deal { margin-bottom: 6px; }
        }

        /* Compact rail labels on small screens */
        @media (max-width: 420px) {
            .live-v2-rail-btn:nth-child(n+5) .live-v2-rail-label { display: none; }
            .live-v2-give-title { -webkit-line-clamp: 1; }
        }

        /* Short height / landscape phones — keep face clear */
        @media (max-height: 560px) {
            .live-v2-scrim--top { height: 18%; }
            .live-v2-scrim--bottom { height: 28%; }
            .live-v2-support { display: none; }
            .live-v2-followers { display: none; }
            .live-v2-chat { max-height: 18vh; }
            .live-shop-comments { max-height: 18vh; }
            .live-v2-deal-col { gap: 4px; }
            .live-v2-deal { margin-bottom: 4px; padding: 6px 8px; }
            .live-v2-deal-img { width: 40px; height: 40px; }
            .live-v2-deal-buy { padding: 8px 10px; }
            .live-v2-deal-stock { display: none; }
            .live-v2-give { padding: 6px 8px; }
            .live-v2-rail { gap: 6px; }
            .live-v2-rail-ico { width: 36px; height: 36px; }
            .live-v2-rail-label { display: none; }
            .live-shop-hearts { height: 100px; bottom: 40px; }
            .live-v2-waiting-avatar { width: 64px; height: 64px; font-size: 24px; }
        }

        @media (max-height: 420px) and (orientation: landscape) {
            .live-v2-top {
                grid-template-columns: 1fr auto;
                gap: 4px 8px;
                padding-top: max(6px, env(safe-area-inset-top, 0px));
            }
            .live-v2-host { max-width: 55%; }
            .live-v2-live-block .live-v2-timer { display: none; }
            .live-v2-deal-col {
                width: min(36%, 150px);
                right: 48px;
            }
            .live-v2-deal { margin-bottom: 4px; }
            .live-v2-deal-stock,
            .live-v2-give { display: none !important; }
            .live-v2-chat {
                width: min(40%, 220px);
                max-height: 28vh;
                bottom: 2px;
            }
            .live-v2-rail {
                flex-direction: row;
                width: auto;
                right: max(8px, env(safe-area-inset-right, 0px));
                bottom: auto;
                top: 50%;
                transform: translateY(-50%);
                gap: 6px;
            }
            .live-shop-hearts { display: none; }
            .live-v2-dock { padding-bottom: max(6px, env(safe-area-inset-bottom, 0px)); }
            .live-v2-shelf { margin-bottom: 4px; padding: 6px 8px; }
            .live-v2-sheet { max-height: 88% !important; }
        }

        /* Tablets & desktop frame */
        @media (min-width: 721px) {
            .live-v2-top {
                padding: 14px 14px 6px;
            }
            .live-v2-avatar { width: 38px; height: 38px; font-size: 14px; }
            .live-v2-host-name { font-size: 13px; }
            .live-v2-deal-col {
                right: 62px;
                width: min(48%, 180px);
                bottom: 8px;
            }
            .live-v2-deal { padding: 10px; margin-bottom: 10px; }
            .live-v2-deal-img { width: 56px; height: 56px; border-radius: 15px; }
            .live-v2-deal-title { font-size: 13px; }
            .live-v2-deal-buy { padding: 11px 16px; font-size: 12px; }
            .live-v2-rail {
                right: 10px;
                gap: 12px;
                width: 50px;
            }
            .live-v2-rail-ico { width: 46px; height: 46px; }
            .live-v2-chat {
                width: min(56%, 250px);
                max-height: 32vh;
                left: 14px;
            }
            .live-shop-comments .live-cmt { font-size: 12px; }
            .live-v2-input { height: 44px; }
            .live-v2-end { height: 44px; }
            .live-v2-dock { padding: 0 14px 14px; }
            .live-shop-shelf-item { width: 80px; }
            .live-shop-shelf-item img,
            .live-shop-shelf-item .ph { height: 62px; }
            .live-v2-sheet {
                border-radius: 22px 22px 0 0;
                padding: 14px 18px 22px;
            }
        }

        /* Large desktop — roomier type, still face-safe */
        @media (min-width: 1100px) {
            .live-v2-deal-col { width: 190px; }
            .live-v2-chat { max-width: 270px; }
            .live-v2-deal-buy { padding: 12px 18px; }
        }

        /* Prefer reduced motion */
        @media (prefers-reduced-motion: reduce) {
            .live-v2-deal,
            .live-v2-give,
            .live-v2-shelf,
            .live-v2-sheet,
            .live-shop-comments .live-cmt,
            .live-shop-toast,
            .live-v2-waiting-avatar,
            .live-v2-live-badge i {
                animation: none !important;
            }
        }

        /* legacy aliases kept for safety */
        .live-shop-card, .live-shop-glass, .live-shop-giveaway { display: contents; }
        .live-shop-icon-btn { display: none; }

        .live-shop-frame.is-live-mode #stream-classic-header,
        .live-shop-frame.is-live-mode #stream-tap-prev,
        .live-shop-frame.is-live-mode #stream-tap-next,
        .live-shop-frame.is-live-mode #stream-hold-zone,
        .live-shop-frame.is-live-mode #stream-viewer-desc { display: none !important; }
        .live-shop-frame.is-live-mode #stream-progress { display: none !important; }
        body.live-stream-open #ai-assistant { display: none !important; }
        body.live-stream-open .story-brand,
        body.live-stream-open .story-close-outer { display: none !important; }

        /* ===== Live setup (настройка стрима) ===== */
        .live-setup-shell {
            background: #f4f5f7;
        }
        .live-setup-panel {
            display: flex;
            flex-direction: column;
            height: 100%;
            max-width: 480px;
            margin: 0 auto;
            background: #fff;
        }
        .live-setup-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 12px 14px;
            border-bottom: 1px solid rgba(0,0,0,0.06);
            background: #fff;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .live-setup-back {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            background: none;
            border: 0;
            cursor: pointer;
            padding: 4px 0;
        }
        .live-setup-logo {
            font-family: inherit;
            font-weight: 800;
            font-size: 14px;
            letter-spacing: -0.02em;
        }
        .live-setup-logo span { color: #7c3aed; }
        .live-setup-preview-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 700;
            color: #6d28d9;
            background: #f3e8ff;
            border: 0;
            border-radius: 999px;
            padding: 7px 10px;
            cursor: pointer;
        }
        .live-setup-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 14px 14px 8px;
            -webkit-overflow-scrolling: touch;
        }
        .live-setup-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .live-setup-avatar {
            width: 48px;
            height: 48px;
            border-radius: 999px;
            overflow: hidden;
            background: #ede9fe;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #6d28d9;
            flex-shrink: 0;
        }
        .live-setup-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .live-setup-name-row { display: flex; align-items: center; gap: 4px; }
        .live-setup-name { font-size: 14px; font-weight: 800; color: #111827; }
        .live-setup-verified { color: #7c3aed; flex-shrink: 0; }
        .live-setup-subs { font-size: 11px; color: #9ca3af; margin-top: 1px; }
        .live-setup-cover-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 700;
            color: #6d28d9;
            background: #f3e8ff;
            border-radius: 999px;
            padding: 8px 10px;
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .live-setup-cover-preview {
            position: relative;
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 12px;
            aspect-ratio: 16/7;
            background: #111;
        }
        .live-setup-cover-preview img { width: 100%; height: 100%; object-fit: cover; }
        .live-setup-cover-clear {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 28px;
            height: 28px;
            border-radius: 999px;
            background: rgba(0,0,0,0.55);
            color: #fff;
            border: 0;
            cursor: pointer;
        }
        .live-setup-title-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 14px;
        }
        .live-setup-heading {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .live-setup-live-badge {
            display: inline-flex;
            align-items: center;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.06em;
            color: #fff;
            background: #ef4444;
            border-radius: 6px;
            padding: 3px 7px;
        }
        .live-setup-lead { font-size: 12px; color: #9ca3af; margin-top: 4px; }
        .live-setup-draft-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
            border: 0;
            border-radius: 12px;
            padding: 9px 11px;
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .live-setup-card {
            background: #fff;
            border: 1px solid rgba(0,0,0,0.07);
            border-radius: 18px;
            padding: 14px;
            margin-bottom: 12px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .live-setup-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
        }
        .live-setup-card-head h3 {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #111827;
        }
        .live-setup-tag {
            font-size: 10px;
            font-weight: 700;
            color: #7c3aed;
            background: #f3e8ff;
            border-radius: 999px;
            padding: 3px 8px;
        }
        .live-setup-tag.is-req { color: #fff; background: #7c3aed; }
        .live-setup-card-hint { font-size: 12px; color: #9ca3af; margin-bottom: 10px; }
        .live-setup-dashed {
            width: 100%;
            border: 1.5px dashed #c4b5fd;
            background: #faf5ff;
            color: #7c3aed;
            font-size: 13px;
            font-weight: 700;
            border-radius: 14px;
            padding: 14px;
            cursor: pointer;
        }
        .live-setup-product-list { margin-top: 10px; display: flex; flex-direction: column; gap: 8px; }
        .live-setup-product-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            border: 1px solid rgba(0,0,0,0.07);
            border-radius: 14px;
            background: #fafafa;
        }
        .live-setup-product-row .thumb {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: #e5e7eb center/cover no-repeat;
            flex-shrink: 0;
        }
        .live-setup-product-row .meta { min-width: 0; flex: 1; }
        .live-setup-product-row .title { font-size: 12px; font-weight: 700; line-height: 1.25; }
        .live-setup-product-row .price { font-size: 13px; font-weight: 800; color: #7c3aed; margin-top: 2px; }
        .live-setup-product-row .actions { display: flex; gap: 4px; flex-shrink: 0; }
        .live-setup-icon-btn {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 0;
            background: #fff;
            color: #6b7280;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .live-setup-settings-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }
        .live-setup-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
        .live-setup-field-label {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            font-weight: 700;
            color: #6b7280;
        }
        .live-setup-select {
            width: 100%;
            height: 38px;
            border-radius: 10px;
            border: 1px solid rgba(0,0,0,0.1);
            background: #fff;
            font-size: 11px;
            font-weight: 600;
            padding: 0 6px;
            color: #111827;
        }
        .live-setup-notify {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: 14px;
            background: #faf5ff;
            border: 1px solid #ede9fe;
        }
        .live-setup-notify-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .live-setup-notify-title { font-size: 12px; font-weight: 700; color: #111827; line-height: 1.3; }
        .live-setup-notify-sub { font-size: 10px; color: #9ca3af; margin-top: 2px; line-height: 1.3; }
        .live-setup-toggle {
            width: 44px;
            height: 26px;
            border-radius: 999px;
            border: 0;
            background: #d1d5db;
            position: relative;
            cursor: pointer;
            flex-shrink: 0;
            transition: background .2s;
        }
        .live-setup-toggle::after {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 20px;
            height: 20px;
            border-radius: 999px;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            transition: transform .2s;
        }
        .live-setup-toggle.is-on { background: #7c3aed; }
        .live-setup-toggle.is-on::after { transform: translateX(18px); }
        .live-setup-footer {
            padding: 12px 14px 18px;
            border-top: 1px solid rgba(0,0,0,0.06);
            background: #fff;
        }
        .live-setup-start {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
            color: #fff;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            border: 0;
            border-radius: 16px;
            padding: 15px;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(109, 40, 217, 0.28);
        }
        .live-setup-warn {
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
            margin-top: 8px;
        }
        .live-setup-tip {
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
            margin-top: 6px;
        }
        .live-setup-give-card {
            border: 1px solid #ede9fe;
            background: #faf5ff;
            border-radius: 14px;
            padding: 12px;
        }
        .live-picker-item {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            text-align: left;
            padding: 8px;
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 14px;
            background: #fff;
            cursor: pointer;
        }
        .live-picker-item.is-selected {
            border-color: #7c3aed;
            background: #faf5ff;
            box-shadow: 0 0 0 1px #7c3aed;
        }
        .live-picker-item .thumb {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #e5e7eb center/cover no-repeat;
            flex-shrink: 0;
        }
        @media (max-width: 420px) {
            .live-setup-settings-grid { grid-template-columns: 1fr; }
            .live-setup-draft-btn span,
            .live-setup-preview-btn { font-size: 10px; }
        }
        .dark .live-setup-shell,
        .dark .live-setup-panel,
        .dark .live-setup-top,
        .dark .live-setup-footer,
        .dark .live-setup-card { background: #111827; }
        .dark .live-setup-name,
        .dark .live-setup-card-head h3,
        .dark .live-setup-notify-title,
        .dark .live-setup-back { color: #f9fafb; }
        .dark .live-setup-card,
        .dark .live-setup-top,
        .dark .live-setup-footer { border-color: rgba(255,255,255,0.08); }
        .dark .live-setup-product-row,
        .dark .live-setup-select,
        .dark .live-picker-item { background: #1f2937; color: #f9fafb; border-color: rgba(255,255,255,0.1); }
        .dark .live-setup-dashed { background: rgba(124,58,237,0.12); }
        body.live-stream-open #stream-nav-prev,
        body.live-stream-open #stream-nav-next { opacity: 0.35; }
        body.live-stream-open #live-return-fab { display: none !important; }
        #live-return-fab {
            position: fixed;
            left: 50%;
            bottom: 88px;
            transform: translateX(-50%);
            z-index: 85;
            display: none;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 999px;
            border: none;
            background: #ef4444;
            color: #fff;
            font-weight: 800;
            font-size: 13px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            cursor: pointer;
        }
        #live-return-fab.is-on { display: inline-flex; }
        .live-product-sheet-panel {
            animation: liveSheetUp .22s ease;
        }
        @keyframes liveSheetUp {
            from { transform: translateY(24px); opacity: 0.6; }
            to { transform: translateY(0); opacity: 1; }
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
        #ai-assistant-toggle {
            animation: aiFabFloat 3.2s ease-in-out infinite;
        }
        #ai-assistant-toggle .ai-fab-avatar {
            position: relative;
        }
        #ai-assistant-toggle .ai-fab-avatar::before,
        #ai-assistant-toggle .ai-fab-avatar::after {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 9999px;
            border: 2px solid rgba(201, 162, 39, 0.45);
            pointer-events: none;
            animation: aiFabPulse 2.8s ease-out infinite;
        }
        #ai-assistant-toggle .ai-fab-avatar::after {
            animation-delay: 1.4s;
        }
        #ai-assistant-toggle .ai-fab-label {
            animation: aiFabLabel 3.2s ease-in-out infinite;
        }
        #ai-assistant-toggle:hover {
            animation-play-state: paused;
            transform: translateY(-4px) scale(1.04);
        }
        #ai-assistant-toggle[aria-expanded="true"] {
            animation: none;
            transform: none;
        }
        #ai-assistant-toggle[aria-expanded="true"] .ai-fab-avatar::before,
        #ai-assistant-toggle[aria-expanded="true"] .ai-fab-avatar::after,
        #ai-assistant-toggle[aria-expanded="true"] .ai-fab-label {
            animation: none;
            opacity: 0.85;
        }
        @keyframes aiFabFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }
        @keyframes aiFabPulse {
            0% { transform: scale(1); opacity: 0.55; }
            70% { transform: scale(1.22); opacity: 0; }
            100% { transform: scale(1.22); opacity: 0; }
        }
        @keyframes aiFabLabel {
            0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(201, 162, 39, 0); }
            50% { opacity: 0.92; box-shadow: 0 0 12px 0 rgba(201, 162, 39, 0.35); }
        }
        @media (prefers-reduced-motion: reduce) {
            #ai-assistant-toggle,
            #ai-assistant-toggle .ai-fab-avatar::before,
            #ai-assistant-toggle .ai-fab-avatar::after,
            #ai-assistant-toggle .ai-fab-label {
                animation: none !important;
            }
        }
        /* Product photo watermark */
        .photo-wm {
            position: relative;
        }
        .photo-wm::after {
            content: 'zakopeyki.kz';
            position: absolute;
            right: 8px;
            bottom: 8px;
            z-index: 6;
            font-family: Sora, system-ui, sans-serif;
            font-weight: 700;
            font-size: 10px;
            letter-spacing: 0.03em;
            line-height: 1;
            color: rgba(255, 255, 255, 0.88);
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.55), 0 0 8px rgba(0, 0, 0, 0.25);
            pointer-events: none;
            user-select: none;
            opacity: 0.9;
        }
        .photo-wm--md::after {
            font-size: 13px;
            right: 12px;
            bottom: 12px;
        }
        .photo-wm--lg::after {
            font-size: 18px;
            right: 16px;
            bottom: 18px;
            letter-spacing: 0.04em;
            opacity: 0.92;
        }
    </style>
</head>
<body class="app-shell text-ink-900 dark:text-gray-100 flex h-screen overflow-hidden select-none relative">
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-5ZJNMVQ2"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
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

    <button type="button" id="live-return-fab" onclick="returnToLiveStream()" aria-label="<?= htmlspecialchars(t('live.back_to_stream')) ?>">
        <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span>
        <?= htmlspecialchars(t('live.back_to_stream')) ?>
    </button>

    <!-- ИИ-помощник: отдельный fixed-слой, не внутри overflow-hidden -->
    <div id="ai-assistant" class="fixed bottom-5 right-5 z-[90] flex flex-col items-end gap-3 pointer-events-none">
        <div id="ai-assistant-panel" class="hidden pointer-events-auto w-[min(calc(100vw-1.5rem),380px)] h-[min(68vh,520px)] glass rounded-2xl shadow-lift border border-ink-900/10 dark:border-white/10 flex flex-col overflow-hidden" role="dialog" aria-label="<?= htmlspecialchars(t('ai.aria')) ?>" aria-hidden="true">
            <div class="px-4 py-3 border-b border-ink-900/10 dark:border-white/10 flex items-center justify-between shrink-0 bg-gradient-to-r from-[#1F4D3A]/12 via-[#C9A227]/08 to-transparent dark:from-[#1F4D3A]/35 dark:via-[#C9A227]/10">
                <div class="flex items-center gap-3 min-w-0">
                    <img src="<?= url('public/assets/img/uly-avatar.png') ?>" alt="" width="48" height="48"
                         class="w-12 h-12 rounded-full object-cover object-top shrink-0 ring-2 ring-[#C9A227]/45 bg-[#E8E6E1]"
                         srcset="<?= url('public/assets/img/uly-avatar@2x.png') ?> 2x">
                    <div class="min-w-0">
                        <p class="font-display font-bold text-sm text-ink-900 dark:text-white truncate"><?= htmlspecialchars(t('ai.title')) ?></p>
                        <p class="text-[11px] text-ink-700/70 dark:text-gray-400 truncate" id="ai-status-text"><?= htmlspecialchars(t('ai.status_ai')) ?></p>
                    </div>
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

        <button type="button" id="ai-assistant-toggle" class="pointer-events-auto group flex flex-col items-center gap-1.5 cursor-pointer transition will-change-transform" aria-expanded="false" aria-controls="ai-assistant-panel" aria-label="<?= htmlspecialchars(t('ai.aria')) ?>">
            <span class="ai-fab-avatar relative block w-[4.5rem] h-[4.5rem] sm:w-20 sm:h-20 rounded-full shadow-lift ring-2 ring-[#C9A227]/50 overflow-hidden bg-[#E8E6E1] border-[3px] border-white dark:border-ink-800">
                <img src="<?= url('public/assets/img/uly-avatar.png') ?>" alt="" width="80" height="80"
                     class="w-full h-full object-cover object-top"
                     srcset="<?= url('public/assets/img/uly-avatar@2x.png') ?> 2x">
            </span>
            <span class="ai-fab-label font-display font-semibold text-[10px] sm:text-[11px] tracking-wide text-white bg-[#1F4D3A] px-2.5 py-1 rounded-full shadow-soft border border-[#C9A227]/35 whitespace-nowrap"><?= htmlspecialchars(t('ai.toggle')) ?></span>
        </button>
    </div>

    <?php \App\Core\View::partial('partials/chat-drawer'); ?>

    <div id="image-lightbox" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-ink-900/85 backdrop-blur-sm p-3 sm:p-6" role="dialog" aria-modal="true" aria-label="<?= htmlspecialchars(t('product.zoom')) ?>">
        <button type="button" id="image-lightbox-close" class="absolute top-3 right-3 sm:top-5 sm:right-5 z-20 w-10 h-10 rounded-xl bg-white/15 hover:bg-white/25 text-white text-xl leading-none flex items-center justify-center transition" aria-label="<?= htmlspecialchars(t('product.close_photo')) ?>">✕</button>
        <button type="button" id="image-lightbox-prev" class="hidden absolute left-2 sm:left-4 z-20 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-white/15 hover:bg-white/25 text-white text-2xl leading-none flex items-center justify-center transition" aria-label="<?= htmlspecialchars(t('product.prev_photo')) ?>">‹</button>
        <button type="button" id="image-lightbox-next" class="hidden absolute right-2 sm:right-4 z-20 w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-white/15 hover:bg-white/25 text-white text-2xl leading-none flex items-center justify-center transition" aria-label="<?= htmlspecialchars(t('product.next_photo')) ?>">›</button>
        <div class="photo-wm photo-wm--lg relative max-w-full max-h-[min(92vh,900px)] flex items-center justify-center">
            <img id="image-lightbox-img" src="" alt="" class="max-w-full max-h-[min(92vh,900px)] w-auto h-auto object-contain rounded-xl shadow-lift select-none pointer-events-none">
        </div>
        <p id="image-lightbox-counter" class="hidden absolute bottom-4 left-1/2 -translate-x-1/2 text-white/80 text-xs font-medium tracking-wide bg-black/35 px-3 py-1 rounded-full"></p>
    </div>

    <script>
        window.__isLoggedIn = <?= \App\Core\Auth::check() ? 'true' : 'false' ?>;
        window.__csrfToken = <?= js_encode(\App\Core\Csrf::token()) ?>;
        window.__loginUrl = <?= js_encode(url('/login')) ?>;
        window.__homeUrl = <?= js_encode(url('/')) ?>;
        window.__favoritesToggleBase = <?= js_encode(rtrim(url('/favorites'), '/') . '/') ?>;
        window.__cartToggleBase = <?= js_encode(rtrim(url('/cart'), '/') . '/') ?>;
        window.__cartCount = <?= (int) (\App\Services\Cart::count()) ?>;
        window.__aiChatUrl = <?= js_encode(url('/ai/chat')) ?>;
        window.__aiMessagesUrl = <?= js_encode(url('/ai/chat/messages')) ?>;
        window.__aiFeedbackUrl = <?= js_encode(url('/ai/chat/feedback')) ?>;
        window.__aiAvatarUrl = <?= js_encode(url('public/assets/img/uly-avatar.png')) ?>;
        window.__aiAvatarUrl2x = <?= js_encode(url('public/assets/img/uly-avatar@2x.png')) ?>;
        window.__chatStartUrl = <?= js_encode(url('/chat/start')) ?>;
        window.__chatBaseUrl = <?= js_encode(rtrim(url('/chat'), '/') . '/') ?>;
        window.__lang = <?= js_encode(\App\Core\Lang::current()) ?>;
        window.__i18n = <?= js_encode(\App\Core\Lang::forJs([
            'ai.welcome', 'ai.suggest_free', 'ai.suggest_exchange', 'ai.suggest_services', 'ai.suggest_sell',
            'ai.suggest_auctions', 'ai.msg_free', 'ai.msg_exchange', 'ai.msg_services', 'ai.msg_sell',
            'ai.msg_auctions', 'ai.error_reply', 'ai.error_network', 'ai.status_ai', 'ai.status_human',
            'ai.status_closed', 'ai.csat_ask', 'ai.csat_thanks', 'ai.waiting_operator', 'ai.pending',
            'js.now',
            'js.live_host', 'js.login_to_stream', 'js.stream_fail', 'js.stream_desc',
            'js.you', 'js.stream_error', 'js.live_connecting', 'js.live_waiting',
            'home.start_stream', 'home.live_preview_waiting', 'home.live_preview_cam_error',
            'home.live_preview_starting',
            'home.live_setup_need_product', 'home.live_setup_no_products', 'home.live_setup_draft_saved',
            'home.live_setup_add_product', 'home.live_setup_add_pod', 'home.live_setup_left',
            'home.live_setup_remove_giveaway', 'home.live_setup_chat_off',
            'live.product_of_day', 'live.buy_now', 'live.products_in_stream',
            'live.login_to_comment', 'live.pin', 'live.no_products',
            'live.subscribe', 'live.top_support', 'live.top_empty', 'live.left',
            'live.giveaway', 'live.participate', 'live.see_all', 'live.question',
            'live.share', 'live.share_copied', 'live.giveaway_joined', 'live.followers',
            'live.add_cart', 'live.back_to_stream', 'live.stay_in_stream_hint',
            'live.added_cart', 'live.buy_new_tab', 'live.buy_short', 'live.shop', 'live.hide_products',
            'card.favorite', 'card.unfavorite',
            'card.add_cart', 'card.in_cart',
            'home.story_link_copied',
            'header.city', 'header.city_choose', 'header.city_detect', 'header.city_detecting', 'header.city_denied',
            'chat.title', 'chat.start_hint', 'chat.send_failed', 'chat.start_failed',
            'product.close_photo', 'product.prev_photo', 'product.next_photo', 'product.zoom',
        ])) ?>;
    </script>
    <script src="<?= url('public/assets/js/app.js') ?>"></script>
</body>
</html>
