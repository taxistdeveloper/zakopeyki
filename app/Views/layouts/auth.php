<?php use App\Helpers\ProductHelper; ?>
<!DOCTYPE html>
<html lang="<?= \App\Core\Lang::htmlLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    <?= $content ?>
</body>
</html>
