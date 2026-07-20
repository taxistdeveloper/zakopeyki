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
                        brand: { 400: '#fbbf24', 500: '#f5a524', 600: '#e08808' },
                        ink: { 900: '#1a1916' }
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
<body class="min-h-screen flex items-center justify-center p-4" style="background: radial-gradient(900px 500px at 20% 0%, rgba(245,165,36,.22), transparent 55%), linear-gradient(160deg,#fff9eb,#f0ece6 50%,#ebe4d8); font-family:'DM Sans',sans-serif;">
    <?= $content ?>
</body>
</html>
