<?php
use App\Core\Auth;
use App\Core\View;
use App\Helpers\ProductHelper;
use App\Services\AMLService;

if (!Auth::check()) {
    return;
}
$requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
if (str_contains($requestPath, 'verify-listing')) {
    return;
}

$listingStatus = AMLService::userListingStatus(Auth::user());
$lotsUrl = ProductHelper::url('/profile?tab=lots');
$verifyUrl = ProductHelper::url('/profile/verify-listing');
$autoOpen = $listingStatus !== 'ok' && (!empty($_SESSION['open_listing_verify']) || !empty($_GET['verify_listing']));
$verifyType = (string) ($_SESSION['listing_verify_type'] ?? ($_GET['type'] ?? ''));
$verifyError = $_SESSION['verify_listing_error'] ?? null;
if (!empty($_SESSION['open_listing_verify'])) {
    unset($_SESSION['open_listing_verify']);
}
if (isset($_SESSION['verify_listing_error'])) {
    unset($_SESSION['verify_listing_error']);
}
?>
<div id="listing-verify-modal"
     data-verified="<?= $listingStatus === 'ok' ? '1' : '0' ?>"
     data-lots="<?= htmlspecialchars($lotsUrl) ?>"
     data-verify="<?= htmlspecialchars($verifyUrl) ?>"
     class="<?= $autoOpen ? '' : 'hidden ' ?>fixed inset-0 z-[70] flex items-end sm:items-center justify-center p-0 sm:p-4 bg-ink-900/55 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="listing-verify-heading">
    <button type="button" class="absolute inset-0 cursor-default" onclick="closeListingVerify()" aria-label="<?= htmlspecialchars(t('verify.close')) ?>"></button>
    <div class="relative w-full sm:max-w-md bg-white dark:bg-ink-800 rounded-t-[28px] sm:rounded-[28px] shadow-lift border border-black/[0.06] dark:border-white/10 p-5 sm:p-6 max-h-[92vh] overflow-y-auto">
        <button type="button" onclick="closeListingVerify()" class="absolute top-3.5 right-3.5 w-9 h-9 rounded-xl text-gray-400 hover:text-ink-900 dark:hover:text-white hover:bg-black/[0.04] dark:hover:bg-white/5 transition" aria-label="<?= htmlspecialchars(t('verify.close')) ?>">✕</button>
        <?php View::partial('partials/listing-verify-card', [
            'verifyUser' => Auth::user(),
            'verifyType' => $verifyType,
            'verifyError' => $verifyError,
        ]); ?>
    </div>
</div>
<script>
function openListingVerify(type) {
    const modal = document.getElementById('listing-verify-modal');
    const lotsBase = modal?.dataset.lots || <?= json_encode(ProductHelper::url('/profile?tab=lots')) ?>;
    if (!modal || modal.dataset.verified === '1') {
        let url = lotsBase;
        if (type) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'type=' + encodeURIComponent(type);
        }
        window.location.href = url;
        return;
    }
    if (type) {
        let hidden = modal.querySelector('input[name="type"]');
        if (!hidden) {
            const form = document.getElementById('listing-verify-form');
            if (form) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'type';
                form.appendChild(hidden);
            }
        }
        if (hidden) hidden.value = type;
    }
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    document.getElementById('listing-verify-iin')?.focus();
}
function closeListingVerify() {
    const modal = document.getElementById('listing-verify-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    document.body.style.overflow = '';
}
(function () {
    const form = document.getElementById('listing-verify-form');
    const input = document.getElementById('listing-verify-iin');
    const err = document.getElementById('listing-verify-iin-error');
    if (!form || !input) return;

    function weightsSum(iin, weights) {
        let sum = 0;
        for (let i = 0; i < 11; i++) sum += parseInt(iin[i], 10) * weights[i];
        return sum;
    }
    function validIin(raw) {
        const iin = String(raw || '').replace(/\D/g, '');
        if (!/^\d{12}$/.test(iin)) return false;
        const century = parseInt(iin[6], 10);
        const base = {1: 1800, 2: 1800, 3: 1900, 4: 1900, 5: 2000, 6: 2000}[century];
        if (base == null) return false;
        const y = base + parseInt(iin.slice(0, 2), 10);
        const m = parseInt(iin.slice(2, 4), 10);
        const d = parseInt(iin.slice(4, 6), 10);
        const dt = new Date(y, m - 1, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d) return false;
        let control = weightsSum(iin, [1,2,3,4,5,6,7,8,9,10,11]) % 11;
        if (control === 10) control = weightsSum(iin, [3,4,5,6,7,8,9,10,11,1,2]) % 11;
        return control < 10 && control === parseInt(iin[11], 10);
    }
    form.addEventListener('submit', function (e) {
        if (validIin(input.value)) {
            if (err) err.classList.add('hidden');
            return;
        }
        e.preventDefault();
        if (err) err.classList.remove('hidden');
        input.focus();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeListingVerify();
    });
})();
</script>
