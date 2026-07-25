<?php use App\Helpers\ProductHelper; ?>
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= \App\Core\Csrf::meta() ?>
    <title><?= htmlspecialchars($title ?? 'Auth') ?> — zakopeyki.kz</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            400: '#3B82F6',
                            500: '#2563EB',
                            600: '#1D4ED8',
                            700: '#1E3A8A',
                        },
                        accent: {
                            400: '#FB923C',
                            500: '#F97316',
                            600: '#EA580C',
                        },
                        ink: { 900: '#0F172A' }
                    },
                    fontFamily: {
                        sans: ['"DM Sans"', 'sans-serif'],
                        display: ['Sora', 'sans-serif'],
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen flex items-center justify-center p-4" style="background: radial-gradient(900px 500px at 20% 0%, rgba(37,99,235,.14), transparent 55%), radial-gradient(700px 400px at 90% 10%, rgba(249,115,22,.1), transparent 50%), linear-gradient(160deg,#F8FAFC,#EFF6FF 50%,#DBEAFE); font-family:'DM Sans',sans-serif;">
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-5ZJNMVQ2"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
    <?= $content ?>
    <script>
        window.__csrfToken = <?= js_encode(\App\Core\Csrf::token()) ?>;
        (function () {
            var token = window.__csrfToken || '';
            if (!token) return;
            document.querySelectorAll('form').forEach(function (form) {
                var method = (form.getAttribute('method') || 'get').toLowerCase();
                if (method !== 'post') return;
                if (form.querySelector('input[name="_csrf"]')) return;
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = '_csrf';
                input.value = token;
                form.appendChild(input);
            });
        })();
    </script>
</body>
</html>
