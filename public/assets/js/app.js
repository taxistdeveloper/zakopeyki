function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return (meta && meta.content) || window.__csrfToken || '';
}

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#96;');
}

(function installCsrf() {
    function injectForms(root) {
        const token = csrfToken();
        if (!token) return;
        (root || document).querySelectorAll('form').forEach(function (form) {
            const method = (form.getAttribute('method') || 'get').toLowerCase();
            if (method !== 'post') return;
            let input = form.querySelector('input[name="_csrf"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = '_csrf';
                form.prepend(input);
            }
            input.value = token;
        });
    }

    injectForms(document);
    document.addEventListener('DOMContentLoaded', function () { injectForms(document); });
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        injectForms(form.parentNode || document);
    }, true);

    const originalFetch = window.fetch;
    window.fetch = function (input, init) {
        init = init ? Object.assign({}, init) : {};
        const method = String(init.method || 'GET').toUpperCase();
        if (method !== 'GET' && method !== 'HEAD') {
            const token = csrfToken();
            if (token) {
                const headers = new Headers(init.headers || {});
                if (!headers.has('X-CSRF-Token')) {
                    headers.set('X-CSRF-Token', token);
                }
                init.headers = headers;

                if (init.body instanceof FormData && !init.body.has('_csrf')) {
                    init.body.append('_csrf', token);
                } else if (init.body instanceof URLSearchParams && !init.body.has('_csrf')) {
                    init.body.append('_csrf', token);
                } else if (typeof init.body === 'string' && headers.get('Content-Type')?.includes('application/x-www-form-urlencoded') && !/[?&]_csrf=/.test(init.body)) {
                    init.body += (init.body ? '&' : '') + '_csrf=' + encodeURIComponent(token);
                }
            }
        }
        return originalFetch.call(this, input, init);
    };
})();

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const main = document.getElementById('main-container');
    if (!sidebar) return;

    if (window.innerWidth < 1024) {
        const willOpen = sidebar.classList.contains('-translate-x-full');
        sidebar.classList.toggle('-translate-x-full');
        overlay?.classList.toggle('hidden', !willOpen);
        return;
    }

    // Desktop: lg:translate-x-0 overrides -translate-x-full, so toggling both
    // leaves the sidebar stuck open. Close = keep -translate-x-full, drop lg:translate-x-0.
    const isOpen = sidebar.classList.contains('lg:translate-x-0');
    if (isOpen) {
        sidebar.classList.remove('lg:translate-x-0');
        sidebar.classList.add('-translate-x-full');
        main?.classList.remove('lg:pl-64');
    } else {
        sidebar.classList.add('lg:translate-x-0');
        sidebar.classList.remove('-translate-x-full');
        main?.classList.add('lg:pl-64');
    }
}

function toggleDarkMode() {
    document.documentElement.classList.toggle('dark');
    localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
}

function toggleNotifications() {
    document.getElementById('notification-dropdown')?.classList.toggle('hidden');
}

(function initTheme() {
    if (localStorage.getItem('theme') === 'dark') {
        document.documentElement.classList.add('dark');
    }
})();

/* ===== City picker (Kazakhstan) ===== */
const KZ_CITIES = [
    { id: 'almaty', lat: 43.238, lon: 76.945, ru: 'Алматы', kk: 'Алматы' },
    { id: 'astana', lat: 51.169, lon: 71.449, ru: 'Астана', kk: 'Астана' },
    { id: 'shymkent', lat: 42.342, lon: 69.590, ru: 'Шымкент', kk: 'Шымкент' },
    { id: 'karaganda', lat: 49.805, lon: 73.109, ru: 'Караганда', kk: 'Қарағанды' },
    { id: 'aktobe', lat: 50.284, lon: 57.167, ru: 'Актобе', kk: 'Ақтөбе' },
    { id: 'taraz', lat: 42.900, lon: 71.366, ru: 'Тараз', kk: 'Тараз' },
    { id: 'pavlodar', lat: 52.287, lon: 76.967, ru: 'Павлодар', kk: 'Павлодар' },
    { id: 'ust-kamenogorsk', lat: 49.948, lon: 82.628, ru: 'Усть-Каменогорск', kk: 'Өскемен' },
    { id: 'semey', lat: 50.411, lon: 80.227, ru: 'Семей', kk: 'Семей' },
    { id: 'atyrau', lat: 47.116, lon: 51.920, ru: 'Атырау', kk: 'Атырау' },
    { id: 'kostanay', lat: 53.220, lon: 63.635, ru: 'Костанай', kk: 'Қостанай' },
    { id: 'kyzylorda', lat: 44.849, lon: 65.482, ru: 'Кызылорда', kk: 'Қызылорда' },
    { id: 'uralsk', lat: 51.230, lon: 51.367, ru: 'Уральск', kk: 'Орал' },
    { id: 'petropavl', lat: 54.875, lon: 69.163, ru: 'Петропавловск', kk: 'Петропавл' },
    { id: 'aktau', lat: 43.651, lon: 51.197, ru: 'Актау', kk: 'Ақтау' },
    { id: 'turkestan', lat: 43.297, lon: 68.252, ru: 'Туркестан', kk: 'Түркістан' },
    { id: 'kokshetau', lat: 53.283, lon: 69.383, ru: 'Кокшетау', kk: 'Көкшетау' },
    { id: 'temirtau', lat: 50.055, lon: 72.965, ru: 'Темиртау', kk: 'Теміртау' },
    { id: 'ekibastuz', lat: 51.730, lon: 75.323, ru: 'Экибастуз', kk: 'Екібастұз' },
    { id: 'zhezkazgan', lat: 47.783, lon: 67.767, ru: 'Жезказган', kk: 'Жезқазған' },
    { id: 'balkhash', lat: 46.848, lon: 74.995, ru: 'Балхаш', kk: 'Балқаш' },
    { id: 'taldykorgan', lat: 45.016, lon: 78.374, ru: 'Талдыкорган', kk: 'Талдықорған' },
];

const CITY_STORAGE_KEY = 'zakopeyki_city';

function cityLabel(city) {
    if (!city) return (window.__i18n && window.__i18n['header.city']) || 'Караганда';
    return (window.__lang === 'kk' ? city.kk : city.ru) || city.ru;
}

function findCityById(id) {
    return KZ_CITIES.find(function (c) { return c.id === id; }) || null;
}

function nearestKzCity(lat, lon) {
    let best = KZ_CITIES[0];
    let bestDist = Infinity;
    for (let i = 0; i < KZ_CITIES.length; i++) {
        const c = KZ_CITIES[i];
        const dLat = (c.lat - lat) * Math.PI / 180;
        const dLon = (c.lon - lon) * Math.PI / 180;
        const a = Math.sin(dLat / 2) ** 2
            + Math.cos(lat * Math.PI / 180) * Math.cos(c.lat * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
        const dist = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        if (dist < bestDist) {
            bestDist = dist;
            best = c;
        }
    }
    return best;
}

function setSelectedCity(city, persist) {
    if (!city) return;
    const label = document.getElementById('city-picker-label');
    if (label) label.textContent = cityLabel(city);
    if (persist !== false) {
        try { localStorage.setItem(CITY_STORAGE_KEY, city.id); } catch (e) { /* ignore */ }
    }
    window.__selectedCity = city;
    document.querySelectorAll('#city-picker-list [data-city-id]').forEach(function (btn) {
        const active = btn.getAttribute('data-city-id') === city.id;
        btn.classList.toggle('bg-brand-50', active);
        btn.classList.toggle('dark:bg-brand-500/15', active);
        btn.classList.toggle('font-semibold', active);
        btn.classList.toggle('text-brand-700', active);
        btn.classList.toggle('dark:text-brand-300', active);
    });
}

function toggleCityPicker(force) {
    const dropdown = document.getElementById('city-picker-dropdown');
    const btn = document.getElementById('city-picker-btn');
    if (!dropdown) return;
    const willOpen = force === true ? true : force === false ? false : dropdown.classList.contains('hidden');
    dropdown.classList.toggle('hidden', !willOpen);
    btn?.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
}

function selectCity(id) {
    const city = findCityById(id);
    if (!city) return;
    setSelectedCity(city, true);
    toggleCityPicker(false);
}

function detectUserCity(manual) {
    const label = document.getElementById('city-picker-label');
    const i18n = window.__i18n || {};
    if (!navigator.geolocation) {
        if (manual && label) label.textContent = i18n['header.city_denied'] || 'Нет доступа к геолокации';
        return;
    }
    if (label) label.textContent = i18n['header.city_detecting'] || 'Определение…';
    toggleCityPicker(false);
    navigator.geolocation.getCurrentPosition(
        function (pos) {
            const city = nearestKzCity(pos.coords.latitude, pos.coords.longitude);
            setSelectedCity(city, true);
        },
        function () {
            if (label) {
                const fallback = findCityById(localStorage.getItem(CITY_STORAGE_KEY)) || findCityById('karaganda');
                setSelectedCity(fallback || KZ_CITIES[3], false);
                if (manual) {
                    label.textContent = i18n['header.city_denied'] || 'Нет доступа к геолокации';
                    setTimeout(function () { setSelectedCity(fallback || KZ_CITIES[3], false); }, 1800);
                }
            }
        },
        { enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 }
    );
}

function initCityPicker() {
    const list = document.getElementById('city-picker-list');
    if (!list) return;

    const sorted = KZ_CITIES.slice().sort(function (a, b) {
        return cityLabel(a).localeCompare(cityLabel(b), window.__lang === 'kk' ? 'kk' : 'ru');
    });
    list.innerHTML = '';
    sorted.forEach(function (city) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('role', 'option');
        btn.setAttribute('data-city-id', city.id);
        btn.className = 'w-full text-left px-3.5 py-2 text-xs text-ink-800 dark:text-gray-200 hover:bg-black/[0.04] dark:hover:bg-white/5 transition';
        btn.textContent = cityLabel(city);
        btn.addEventListener('click', function () { selectCity(city.id); });
        list.appendChild(btn);
    });

    let saved = null;
    try { saved = localStorage.getItem(CITY_STORAGE_KEY); } catch (e) { /* ignore */ }
    const city = findCityById(saved) || findCityById('karaganda');
    setSelectedCity(city, false);

    if (!saved) {
        detectUserCity(false);
    }
}

document.addEventListener('DOMContentLoaded', initCityPicker);

document.addEventListener('click', function (e) {
    const dropdown = document.getElementById('notification-dropdown');
    if (dropdown && !dropdown.classList.contains('hidden') && !e.target.closest('.relative')) {
        dropdown.classList.add('hidden');
    }
    const cityDrop = document.getElementById('city-picker-dropdown');
    if (cityDrop && !cityDrop.classList.contains('hidden') && !e.target.closest('#city-picker')) {
        toggleCityPicker(false);
    }
});

/* ===== Stories ===== */
// Переносим полноэкранные модалки в body: внутри анимированных/overflow-обёрток
// position:fixed позиционируется неверно, и просмотрщик уезжает вниз страницы.
function portalStoryModals() {
    ['story-viewer', 'stream-viewer', 'story-create-modal', 'story-create-preview', 'whats-new-modal', 'live-start-preview-modal', 'live-setup-modal', 'live-product-picker', 'live-giveaway-editor', 'seller-profile-modal'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el && el.parentElement !== document.body) {
            document.body.appendChild(el);
        }
    });
}
document.addEventListener('DOMContentLoaded', portalStoryModals);
portalStoryModals();

/* ===== What's new (после git pull / deploy) ===== */
var WHATS_NEW_KEY = 'whats_new_seen';

function closeWhatsNew() {
    var data = window.__whatsNew;
    if (data && data.version) {
        try { localStorage.setItem(WHATS_NEW_KEY, data.version); } catch (e) { /* ignore */ }
    }
    document.getElementById('whats-new-modal')?.classList.add('hidden');
    document.body.style.overflow = '';
    try {
        var url = new URL(window.location.href);
        if (url.searchParams.has('whats_new')) {
            url.searchParams.delete('whats_new');
            window.history.replaceState({}, '', url.pathname + url.search + url.hash);
        }
    } catch (e) { /* ignore */ }
}

function initWhatsNew() {
    var data = window.__whatsNew;
    var modal = document.getElementById('whats-new-modal');
    if (!data || !data.version || !modal) return;
    var force = false;
    try { force = new URLSearchParams(window.location.search).get('whats_new') === '1'; } catch (e) { /* ignore */ }
    var seen = null;
    try { seen = localStorage.getItem(WHATS_NEW_KEY); } catch (e) { /* ignore */ }
    if (!force && seen === data.version) return;
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeWhatsNew();
    });
}
document.addEventListener('DOMContentLoaded', initWhatsNew);

let storyGroupIndex = 0;
let storyItemIndex = 0;
let storyTimer = null;
const STORY_DURATION = 5000;

function openStoryCreate() {
    const modal = document.getElementById('story-create-modal');
    if (!modal) return;
    loadStoryCreateDraft();
    updateStoryCreateCounters();
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeStoryCreate() {
    document.getElementById('story-create-modal')?.classList.add('hidden');
    closeStoryCreatePreview();
    document.body.style.overflow = '';
}

const STORY_CREATE_DRAFT_KEY = 'zakopeyki_story_create_draft';
let storyCreateImageUrl = '';

function onStoryCreateImageChange(e) {
    const file = e.target && e.target.files && e.target.files[0];
    const zone = document.getElementById('story-create-upload-zone');
    const preview = document.getElementById('story-create-media-preview');
    const img = document.getElementById('story-create-media-img');
    if (storyCreateImageUrl) {
        URL.revokeObjectURL(storyCreateImageUrl);
        storyCreateImageUrl = '';
    }
    if (!file) {
        if (zone) zone.classList.remove('hidden');
        if (preview) preview.classList.add('hidden');
        if (img) img.src = '';
        return;
    }
    storyCreateImageUrl = URL.createObjectURL(file);
    if (img) img.src = storyCreateImageUrl;
    if (zone) zone.classList.add('hidden');
    if (preview) preview.classList.remove('hidden');
}

function clearStoryCreateImage() {
    const input = document.getElementById('story-create-image');
    if (input) input.value = '';
    onStoryCreateImageChange({ target: { files: [] } });
}

function updateStoryCreateCounters() {
    const caption = document.getElementById('story-create-caption');
    const desc = document.getElementById('story-create-desc');
    const cCount = document.getElementById('story-create-caption-count');
    const dCount = document.getElementById('story-create-desc-count');
    if (caption && cCount) cCount.textContent = (caption.value || '').length + '/280';
    if (desc && dCount) dCount.textContent = (desc.value || '').length + '/200';
}

function toggleStoryCreateNotify() {
    const btn = document.getElementById('story-create-notify-toggle');
    if (!btn) return;
    const on = !btn.classList.contains('is-on');
    btn.classList.toggle('is-on', on);
    btn.setAttribute('aria-checked', on ? 'true' : 'false');
    const input = document.getElementById('story-create-notify-input');
    if (input) input.value = on ? '1' : '0';
}

function buildStoryCreateCaption() {
    const title = (document.getElementById('story-create-caption')?.value || '').trim();
    const desc = (document.getElementById('story-create-desc')?.value || '').trim();
    if (title && desc) return (title + '\n\n' + desc).slice(0, 280);
    return (title || desc).slice(0, 280);
}

function saveStoryCreateDraft() {
    try {
        const data = {
            caption: document.getElementById('story-create-caption')?.value || '',
            desc: document.getElementById('story-create-desc')?.value || '',
            notify: document.getElementById('story-create-notify-toggle')?.classList.contains('is-on') !== false,
        };
        localStorage.setItem(STORY_CREATE_DRAFT_KEY, JSON.stringify(data));
        alert(window.__i18n?.['home.story_create_draft_saved'] || window.__i18n?.['home.live_setup_draft_saved'] || 'Черновик сохранён');
    } catch (err) {
        alert(window.__i18n?.['home.story_create_draft_saved'] || 'Черновик сохранён');
    }
}

function loadStoryCreateDraft() {
    try {
        const raw = localStorage.getItem(STORY_CREATE_DRAFT_KEY);
        if (!raw) return;
        const data = JSON.parse(raw);
        const caption = document.getElementById('story-create-caption');
        const desc = document.getElementById('story-create-desc');
        const notify = document.getElementById('story-create-notify-toggle');
        if (caption && data.caption != null) caption.value = data.caption;
        if (desc && data.desc != null) desc.value = data.desc;
        if (notify) {
            const on = data.notify !== false;
            notify.classList.toggle('is-on', on);
            notify.setAttribute('aria-checked', on ? 'true' : 'false');
            const input = document.getElementById('story-create-notify-input');
            if (input) input.value = on ? '1' : '0';
        }
    } catch (err) { /* ignore */ }
}

function prepareStoryCreateSubmit() {
    const captionEl = document.getElementById('story-create-caption');
    const merged = buildStoryCreateCaption();
    const hasImage = !!(document.getElementById('story-create-image')?.files?.length);
    if (!hasImage) {
        alert(window.__i18n?.['home.story_create_need_photo'] || 'Загрузите фото для истории');
        return false;
    }
    if (captionEl) captionEl.value = merged;
    const notifyInput = document.getElementById('story-create-notify-input');
    const notifyBtn = document.getElementById('story-create-notify-toggle');
    if (notifyInput && notifyBtn) {
        notifyInput.value = notifyBtn.classList.contains('is-on') ? '1' : '0';
    }
    try { localStorage.removeItem(STORY_CREATE_DRAFT_KEY); } catch (err) { /* ignore */ }
    return true;
}

function previewStoryCreate() {
    const frame = document.getElementById('story-create-preview');
    if (!frame) return;
    const hasImage = !!(document.getElementById('story-create-image')?.files?.length) && storyCreateImageUrl;
    if (!hasImage) {
        alert(window.__i18n?.['home.story_create_need_photo'] || 'Загрузите фото для истории');
        return;
    }
    const text = buildStoryCreateCaption();
    const bgEl = document.getElementById('story-create-preview-bg');
    const imgEl = document.getElementById('story-create-preview-img');
    const textEl = document.getElementById('story-create-preview-text');
    if (bgEl) bgEl.style.background = '#111';
    if (imgEl) {
        imgEl.src = storyCreateImageUrl;
        imgEl.classList.remove('hidden');
    }
    if (textEl) textEl.textContent = text;
    frame.classList.remove('hidden');
}

function closeStoryCreatePreview() {
    document.getElementById('story-create-preview')?.classList.add('hidden');
}

function openStoryViewer(groupIndex) {
    const groups = window.__storyGroups || [];
    if (!groups[groupIndex]) return;
    storyGroupIndex = groupIndex;
    storyItemIndex = 0;
    document.getElementById('story-viewer')?.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    sizeStoryFrame();
    renderStory();
}

function sizeStoryFrame() {
    document.querySelectorAll('#story-viewer .story-frame, #stream-viewer .story-frame').forEach(function (frame) {
        if (window.matchMedia('(max-width: 720px)').matches) {
            frame.style.width = '100vw';
            frame.style.height = '100dvh';
            return;
        }
        // Почти вся высота окна — серые поля только по бокам
        const maxH = window.innerHeight - 8;
        const maxW = Math.min(480, window.innerWidth - 100);
        let h = maxH;
        let w = h * 9 / 16;
        if (w > maxW) {
            w = maxW;
            h = w * 16 / 9;
        }
        frame.style.width = Math.round(w) + 'px';
        frame.style.height = Math.round(h) + 'px';
    });
}

window.addEventListener('resize', sizeStoryFrame);

function closeStoryViewer() {
    clearTimeout(storyTimer);
    document.getElementById('story-viewer')?.classList.add('hidden');
    document.body.style.overflow = '';
}

function currentStory() {
    const groups = window.__storyGroups || [];
    const group = groups[storyGroupIndex];
    if (!group) return null;
    return { group, story: group.stories[storyItemIndex] };
}

function jumpStoryGroup(delta) {
    const groups = window.__storyGroups || [];
    const next = storyGroupIndex + delta;
    if (next < 0 || next >= groups.length) return;
    storyGroupIndex = next;
    storyItemIndex = 0;
    renderStory();
}

function peekStoryHtml(group) {
    if (!group || !group.stories || !group.stories.length) return '';
    const s = group.stories[0];
    let bg = '';
    let emoji = '';
    if (s.image) {
        const src = escapeAttr((window.__storyUploadBase || '') + s.image);
        bg = 'background-image:url(\'' + src + '\')';
    } else {
        const c1 = /^#[0-9A-Fa-f]{6}$/.test(String(s.bg_color || '')) ? s.bg_color : '#2563EB';
        bg = 'background:linear-gradient(160deg,' + c1 + ',#111)';
        emoji = '<span class="story-peek-emoji">' + escapeHtml(s.emoji || '✨') + '</span>';
    }
    return '<div class="story-peek-bg" style="' + bg + '"></div>' + emoji +
        '<span class="story-peek-name">' + escapeHtml(group.user_name || '') + '</span>';
}

function renderStoryPeeks() {
    const groups = window.__storyGroups || [];
    const prev = document.getElementById('story-peek-prev');
    const next = document.getElementById('story-peek-next');
    const prevGroup = groups[storyGroupIndex - 1];
    const nextGroup = groups[storyGroupIndex + 1];

    if (prev) {
        if (prevGroup) {
            prev.innerHTML = peekStoryHtml(prevGroup);
            prev.classList.remove('is-hidden');
        } else {
            prev.innerHTML = '';
            prev.classList.add('is-hidden');
        }
    }
    if (next) {
        if (nextGroup) {
            next.innerHTML = peekStoryHtml(nextGroup);
            next.classList.remove('is-hidden');
        } else {
            next.innerHTML = '';
            next.classList.add('is-hidden');
        }
    }
}

function renderStory() {
    clearTimeout(storyTimer);
    const ctx = currentStory();
    if (!ctx) {
        closeStoryViewer();
        return;
    }
    const { group, story } = ctx;

    const avatarEl = document.getElementById('story-viewer-avatar');
    if (group.avatar_url) {
        avatarEl.innerHTML = '<img src="' + escapeAttr(group.avatar_url) + '" alt="" class="w-full h-full object-cover">';
    } else {
        avatarEl.innerHTML = '';
        avatarEl.textContent = group.user_avatar || '?';
    }
    document.getElementById('story-viewer-name').textContent = group.user_name || '';
    document.getElementById('story-viewer-time').textContent = timeAgo(story.created_at);

    const progress = document.getElementById('story-progress');
    progress.innerHTML = group.stories.map((_, i) => {
        const filled = i < storyItemIndex;
        return '<div class="story-progress-bar"><span data-bar="' + i + '" style="width:' + (filled ? '100%' : '0') + '"></span></div>';
    }).join('');

    const img = document.getElementById('story-image');
    const bg = document.getElementById('story-bg');
    const emojiWrap = document.getElementById('story-emoji');
    const emoji = document.getElementById('story-emoji-icon') || emojiWrap;
    const captionCenter = document.getElementById('story-caption-center');
    const storyText = story.caption || '';

    if (story.image) {
        img.src = window.__storyUploadBase + story.image;
        img.classList.remove('hidden');
        bg.classList.remove('story-text-bg');
        bg.style.background = '#000';
        bg.style.removeProperty('--story-c1');
        bg.style.removeProperty('--story-c2');
        emojiWrap?.classList.add('hidden');
        captionCenter?.classList.add('hidden');
    } else {
        img.classList.add('hidden');
        img.removeAttribute('src');
        const c1 = story.bg_color || '#2563EB';
        const c2 = shadeHex(c1, -38);
        bg.classList.add('story-text-bg');
        bg.style.background = '';
        bg.style.setProperty('--story-c1', c1);
        bg.style.setProperty('--story-c2', c2);
        if (emoji) emoji.textContent = story.emoji || '✨';
        emojiWrap?.classList.remove('hidden');
        if (captionCenter) {
            captionCenter.textContent = storyText;
            captionCenter.classList.toggle('hidden', !storyText);
        }
    }

    renderStoryProduct(group.product || null);

    const groups = window.__storyGroups || [];
    const canPrev = storyItemIndex > 0 || storyGroupIndex > 0;
    const canNext = storyItemIndex < group.stories.length - 1 || storyGroupIndex < groups.length - 1;
    document.getElementById('story-nav-prev')?.classList.toggle('is-hidden', !canPrev);
    document.getElementById('story-nav-next')?.classList.toggle('is-hidden', !canNext);

    const canDelete = window.__isAdmin || (window.__currentUserId && Number(story.user_id) === Number(window.__currentUserId));
    const delWrap = document.getElementById('story-delete-wrap');
    const delForm = document.getElementById('story-delete-form');
    if (canDelete) {
        delWrap.classList.remove('hidden');
        delForm.action = window.__storyDeleteBase + story.id + '/delete';
    } else {
        delWrap.classList.add('hidden');
    }

    const likeBtn = document.getElementById('story-like-btn');
    if (likeBtn) {
        const liked = !!storyLiked[storyKey(story)];
        likeBtn.classList.toggle('is-liked', liked);
        const path = likeBtn.querySelector('svg path');
        if (path) path.setAttribute('fill', liked ? 'currentColor' : 'none');
    }

    if (document.activeElement && document.activeElement.id === 'story-reply-input') {
        return;
    }

    requestAnimationFrame(function () {
        const bar = progress.querySelector('[data-bar="' + storyItemIndex + '"]');
        if (bar) {
            bar.style.transition = 'width ' + STORY_DURATION + 'ms linear';
            bar.style.width = '100%';
        }
    });

    storyTimer = setTimeout(nextStory, STORY_DURATION);
}

function storyKey(story) {
    return String(story && story.id != null ? story.id : '');
}

const storyLiked = {};

function renderStoryProduct(product) {
    const card = document.getElementById('story-product-card');
    if (!card) return;
    if (!product || !product.id) {
        card.classList.add('hidden');
        return;
    }
    card.classList.remove('hidden');
    card.href = product.url || '#';
    document.getElementById('story-product-title').textContent = product.title || '';
    document.getElementById('story-product-price').textContent = product.price || '';
    const img = document.getElementById('story-product-img');
    const ph = document.getElementById('story-product-ph');
    if (product.image) {
        img.src = product.image;
        img.classList.remove('hidden');
        ph?.classList.add('hidden');
    } else {
        img.classList.add('hidden');
        img.removeAttribute('src');
        ph?.classList.remove('hidden');
    }
}

function pauseStoryTimer() {
    clearTimeout(storyTimer);
    const bar = document.querySelector('#story-progress [data-bar="' + storyItemIndex + '"]');
    if (bar) {
        const w = bar.getBoundingClientRect().width;
        const parentW = bar.parentElement.getBoundingClientRect().width || 1;
        bar.style.transition = 'none';
        bar.style.width = ((w / parentW) * 100) + '%';
    }
}

function resumeStoryTimer() {
    if (document.getElementById('story-viewer')?.classList.contains('hidden')) return;
    const progress = document.getElementById('story-progress');
    const bar = progress?.querySelector('[data-bar="' + storyItemIndex + '"]');
    if (bar) {
        const current = parseFloat(bar.style.width) || 0;
        const leftMs = Math.max(400, STORY_DURATION * (1 - current / 100));
        requestAnimationFrame(function () {
            bar.style.transition = 'width ' + leftMs + 'ms linear';
            bar.style.width = '100%';
        });
        clearTimeout(storyTimer);
        storyTimer = setTimeout(nextStory, leftMs);
    } else {
        storyTimer = setTimeout(nextStory, STORY_DURATION);
    }
}

function bindStoryChrome() {
    if (window.__storyChromeBound) return;
    window.__storyChromeBound = true;

    const reply = document.getElementById('story-reply-input');
    reply?.addEventListener('focus', pauseStoryTimer);
    reply?.addEventListener('blur', function () {
        if (!(reply.value || '').trim()) resumeStoryTimer();
    });
    reply?.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            reply.blur();
            reply.value = '';
            resumeStoryTimer();
        }
    });

    document.getElementById('story-like-btn')?.addEventListener('click', function (e) {
        e.stopPropagation();
        const ctx = currentStory();
        if (!ctx) return;
        const key = storyKey(ctx.story);
        storyLiked[key] = !storyLiked[key];
        this.classList.toggle('is-liked', !!storyLiked[key]);
        const path = this.querySelector('svg path');
        if (path) path.setAttribute('fill', storyLiked[key] ? 'currentColor' : 'none');
    });

    document.getElementById('story-share-btn')?.addEventListener('click', function (e) {
        e.stopPropagation();
        const url = window.location.href;
        const done = function () {
            alert(window.__i18n?.['home.story_link_copied'] || 'Ссылка скопирована');
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done).catch(done);
        } else {
            done();
        }
    });

    document.getElementById('story-product-card')?.addEventListener('click', function (e) {
        e.stopPropagation();
    });
}

document.addEventListener('DOMContentLoaded', bindStoryChrome);
bindStoryChrome();

function shadeHex(hex, percent) {
    const raw = String(hex || '').replace('#', '');
    if (raw.length !== 3 && raw.length !== 6) return '#9a3412';
    const full = raw.length === 3
        ? raw.split('').map(function (c) { return c + c; }).join('')
        : raw;
    const num = parseInt(full, 16);
    if (Number.isNaN(num)) return '#9a3412';
    let r = (num >> 16) & 255;
    let g = (num >> 8) & 255;
    let b = num & 255;
    const t = percent < 0 ? 0 : 255;
    const p = Math.abs(percent) / 100;
    r = Math.round((t - r) * p + r);
    g = Math.round((t - g) * p + g);
    b = Math.round((t - b) * p + b);
    return '#' + [r, g, b].map(function (v) {
        return v.toString(16).padStart(2, '0');
    }).join('');
}

function nextStory() {
    const groups = window.__storyGroups || [];
    const group = groups[storyGroupIndex];
    if (!group) return closeStoryViewer();

    if (storyItemIndex < group.stories.length - 1) {
        storyItemIndex++;
        return renderStory();
    }
    if (storyGroupIndex < groups.length - 1) {
        storyGroupIndex++;
        storyItemIndex = 0;
        return renderStory();
    }
    closeStoryViewer();
}

function prevStory() {
    if (storyItemIndex > 0) {
        storyItemIndex--;
        return renderStory();
    }
    if (storyGroupIndex > 0) {
        storyGroupIndex--;
        const group = window.__storyGroups[storyGroupIndex];
        storyItemIndex = Math.max(0, (group && group.stories ? group.stories.length : 1) - 1);
        return renderStory();
    }
    renderStory();
}

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const diff = (Date.now() - new Date(dateStr.replace(' ', 'T')).getTime()) / 1000;
    if (diff < 60) return window.__i18n?.['js.now'] || 'сейчас';
    if (diff < 3600) return Math.floor(diff / 60) + ' мин';
    if (diff < 86400) return Math.floor(diff / 3600) + ' ч';
    return Math.floor(diff / 86400) + ' д';
}

document.addEventListener('keydown', function (e) {
    const storyViewer = document.getElementById('story-viewer');
    const streamViewer = document.getElementById('stream-viewer');
    const livePreview = document.getElementById('live-start-preview-modal');
    const livePicker = document.getElementById('live-product-picker');
    const liveGive = document.getElementById('live-giveaway-editor');
    const liveSetup = document.getElementById('live-setup-modal');
    const storyCreatePreview = document.getElementById('story-create-preview');
    const storyCreate = document.getElementById('story-create-modal');

    if (livePicker && !livePicker.classList.contains('hidden')) {
        if (e.key === 'Escape') closeLiveProductPicker();
        return;
    }
    if (liveGive && !liveGive.classList.contains('hidden')) {
        if (e.key === 'Escape') closeLiveGiveawayEditor();
        return;
    }
    if (livePreview && !livePreview.classList.contains('hidden')) {
        if (e.key === 'Escape') closeLiveStartPreview();
        return;
    }
    if (liveSetup && !liveSetup.classList.contains('hidden')) {
        if (e.key === 'Escape') closeLiveSetup();
        return;
    }
    if (storyCreatePreview && !storyCreatePreview.classList.contains('hidden')) {
        if (e.key === 'Escape') closeStoryCreatePreview();
        return;
    }
    if (storyCreate && !storyCreate.classList.contains('hidden')) {
        if (e.key === 'Escape') closeStoryCreate();
        return;
    }

    if (storyViewer && !storyViewer.classList.contains('hidden')) {
        if (e.key === 'Escape') closeStoryViewer();
        if (e.key === 'ArrowRight') nextStory();
        if (e.key === 'ArrowLeft') prevStory();
        return;
    }

    if (streamViewer && !streamViewer.classList.contains('hidden')) {
        if (e.key === 'Escape') closeStreamViewer();
        if (e.key === 'ArrowRight') nextStream();
        if (e.key === 'ArrowLeft') prevStream();
        if (e.key === 'm' || e.key === 'M') toggleStreamMute();
        if (e.key === ' ') {
            e.preventDefault();
            const video = document.getElementById('stream-video');
            if (video) {
                if (video.paused) resumeStreamHold();
                else pauseStreamHold();
            }
        }
    }
});

/* ===== Streams Live ===== */
let streamIndex = 0;
let streamMuted = false;
let streamHoldTimer = null;
let streamProgressRaf = null;

function openStreamViewer(index) {
    const streams = window.__streams || [];
    if (!streams[index]) return;
    streamIndex = index;
    document.getElementById('stream-viewer')?.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    document.body.classList.add('live-stream-open');
    try { toggleAiAssistant(false); } catch (e) { /* ignore */ }
    updateLiveReturnFab();
    sizeStoryFrame();
    renderStreamReel();
    bindStreamGestures();
}

function closeStreamViewer() {
    cancelAnimationFrame(streamProgressRaf);
    clearTimeout(window.__streamEmbedTimer);
    const video = document.getElementById('stream-video');
    const iframe = document.getElementById('stream-iframe');
    const livePanel = document.getElementById('stream-live-panel');
    if (video) {
        video.pause();
        video.removeAttribute('src');
        video.load();
        video.classList.remove('hidden');
    }
    if (iframe) {
        iframe.src = '';
        iframe.classList.add('hidden');
    }
    if (livePanel) livePanel.classList.add('hidden');
    showLiveUnmuteBtn(false);
    // зритель уходит — отключаем WebRTC; хост продолжает эфир
    if (!window.__myLiveId) {
        stopLiveRtc();
        stopLiveCamera();
    }
    stopLiveShop();
    closeLiveProductSheet();
    document.querySelector('#stream-viewer .live-shop-frame')?.classList.remove('is-live-mode');
    document.getElementById('stream-viewer')?.classList.add('hidden');
    document.getElementById('stream-paused')?.classList.add('hidden');
    document.body.style.overflow = '';
    document.body.classList.remove('live-stream-open');
    updateLiveReturnFab();
}

function streamSrc(stream) {
    if (stream.file) return window.__streamVideoBase + stream.file;
    if (stream.url) return stream.url;
    return null;
}

function renderStreamReel() {
    cancelAnimationFrame(streamProgressRaf);
    clearTimeout(window.__streamEmbedTimer);
    const streams = window.__streams || [];
    const stream = streams[streamIndex];
    if (!stream) return closeStreamViewer();

    document.getElementById('stream-viewer-avatar').textContent = stream.author_avatar || '?';
    document.getElementById('stream-viewer-name').textContent = stream.author_name || '';
    document.getElementById('stream-viewer-title').textContent = stream.title || '';

    const liveBadge = document.getElementById('stream-live-badge');
    liveBadge.classList.toggle('hidden', !stream.is_live);

    const desc = document.getElementById('stream-viewer-desc');
    if (stream.description && !stream.is_live) {
        desc.textContent = stream.description;
        desc.classList.remove('hidden');
    } else {
        desc.classList.add('hidden');
    }

    const progress = document.getElementById('stream-progress');
    progress.innerHTML = streams.map((_, i) => {
        const filled = i < streamIndex ? '100%' : '0%';
        return '<div class="story-progress-bar"><span class="stream-bar" data-sbar="' + i + '" style="width:' + filled + '"></span></div>';
    }).join('');

    document.getElementById('stream-nav-prev')?.classList.toggle('is-hidden', streamIndex <= 0);
    document.getElementById('stream-nav-next')?.classList.toggle('is-hidden', streamIndex >= streams.length - 1);

    const video = document.getElementById('stream-video');
    const iframe = document.getElementById('stream-iframe');
    const livePanel = document.getElementById('stream-live-panel');
    const endBtn = document.getElementById('stream-end-live-btn');
    const cam = document.getElementById('stream-live-cam');
    const frame = document.querySelector('#stream-viewer .live-shop-frame');

    iframe.classList.add('hidden');
    iframe.src = '';
    livePanel.classList.add('hidden');
    video.classList.add('hidden');
    if (endBtn) endBtn.classList.add('hidden');
    if (cam && Number(stream.user_id) !== Number(window.__currentUserId)) {
        // не прячем уже подключённый эфир при повторном render
        if (!(stream.is_live && liveViewerStreamId === stream.id && cam.srcObject)) {
            cam.classList.add('hidden');
        }
    }

    const isHost = stream.is_live && Number(stream.user_id) === Number(window.__currentUserId);
    const hintEl = document.getElementById('stream-live-hint');

    if (stream.is_live) {
        livePanel.classList.remove('hidden');
        document.getElementById('stream-live-avatar').textContent = stream.author_avatar || '?';
        document.getElementById('stream-live-host').textContent = stream.author_name || window.__i18n?.['js.live_host'] || 'Эфир';
        frame?.classList.add('is-live-mode');
        if (isHost) {
            if (endBtn) endBtn.classList.remove('hidden');
            if (hintEl) hintEl.textContent = window.__i18n?.['js.stream_desc'] || 'Прямой эфир — не сохраняется';
            showLiveUnmuteBtn(false);
            startLiveCameraPreview();
            cam.classList.remove('hidden');
            cam.muted = true;
        } else {
            if (endBtn) endBtn.classList.add('hidden');
            if (hintEl) hintEl.textContent = window.__i18n?.['js.live_connecting'] || 'Подключение к эфиру…';
            stopLiveCamera();
            startLiveViewerRtc(stream.id);
        }
        startLiveShop(stream);
        animateFakeProgress(30000);
    } else {
        frame?.classList.remove('is-live-mode');
        stopLiveShop();
        stopLiveRtc();
        stopLiveCamera();
        video.classList.remove('hidden');
        video.muted = streamMuted;
        updateMuteBtn();

        const src = streamSrc(stream);
        if (src) {
            if (stream.cover) {
                video.poster = window.__streamCoverBase + stream.cover;
            } else {
                video.removeAttribute('poster');
            }
            video.src = src;
            video.play().catch(function () {
                video.muted = true;
                streamMuted = true;
                updateMuteBtn();
                video.play().catch(function () {});
            });
            trackStreamProgress(video);
            video.onended = function () { nextStream(); };
        } else if (stream.embed) {
            video.classList.add('hidden');
            video.pause();
            iframe.classList.remove('hidden');
            iframe.src = stream.embed;
            window.__streamEmbedTimer = setTimeout(nextStream, 15000);
            animateFakeProgress(15000);
        }
    }

    const canDelete = !stream.is_live && (window.__isAdmin || (window.__currentUserId && Number(stream.user_id) === Number(window.__currentUserId)));
    const delWrap = document.getElementById('stream-delete-wrap');
    const delForm = document.getElementById('stream-delete-form');
    if (canDelete) {
        delWrap.classList.remove('hidden');
        delForm.action = window.__streamDeleteBase + stream.id + '/delete';
    } else {
        delWrap.classList.add('hidden');
    }

    document.getElementById('stream-paused')?.classList.add('hidden');
}

/* ===== Live (не хранится) + WebRTC ===== */
let liveHeartbeatTimer = null;
window.__myLiveId = null;
let liveMediaStream = null;
let liveMediaPromise = null;
let liveHostPcs = {};
let liveHostStreamId = null;
let livePendingPeers = [];
let liveViewerPc = null;
let liveViewerPeerId = null;
let liveViewerStreamId = null;
let liveSignalPollTimer = null;
let liveSignalAfterId = 0;
let liveSignalRole = null;
const LIVE_ICE_SERVERS = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' }
    ]
};

function liveRandomPeerId() {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID().replace(/-/g, '').slice(0, 32);
    }
    return 'p' + Math.random().toString(36).slice(2) + Date.now().toString(36);
}

let liveStartConfirmBusy = false;
const LIVE_SETUP_DRAFT_KEY = 'live_setup_draft_v1';
let liveSetupState = {
    products: [],
    catalog: [],
    featuredId: 0,
    featuredPrice: null,
    giveaway: null,
    duration: 7200,
    visibility: 'all',
    chatEnabled: true,
    notifySubs: true,
    coverFile: null,
    coverUrl: null
};
let livePickerMode = 'products'; // products | pod
let livePickerSelected = {};

function startLiveStream() {
    if (!window.__currentUserId) {
        alert(window.__i18n?.['js.login_to_stream'] || 'Войдите, чтобы начать эфир');
        return;
    }
    if (window.__myLiveId) {
        openStreamViewer(0);
        return;
    }
    openLiveSetup();
}

function openLiveSetup() {
    const modal = document.getElementById('live-setup-modal');
    if (!modal) {
        openLiveStartPreview();
        return;
    }
    loadLiveSetupDraft();
    renderLiveSetup();
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    fetchLiveSetupCatalog();
}

function closeLiveSetup() {
    const modal = document.getElementById('live-setup-modal');
    if (modal) modal.classList.add('hidden');
    closeLiveProductPicker();
    closeLiveGiveawayEditor();
    const preview = document.getElementById('live-start-preview-modal');
    const previewOpen = preview && !preview.classList.contains('hidden');
    if (!previewOpen) {
        document.body.style.overflow = '';
        if (!window.__myLiveId) stopLiveCamera();
    }
}

function openLiveStartPreviewFromSetup() {
    syncLiveSetupFromForm();
    openLiveStartPreview();
}

function submitLiveSetupStart() {
    syncLiveSetupFromForm();
    if (!liveSetupState.products.length) {
        alert(window.__i18n?.['home.live_setup_need_product'] || 'Добавьте минимум 1 товар');
        return;
    }
    openLiveStartPreview();
}

function setLiveBtnLabel(btn, text) {
    if (!btn) return;
    const label = btn.querySelector('.live-btn-label');
    if (label) label.textContent = text;
    else btn.textContent = text;
}

function openLiveStartPreview() {
    const modal = document.getElementById('live-start-preview-modal');
    if (!modal) {
        confirmStartLiveStream();
        return;
    }
    liveStartConfirmBusy = false;
    const btn = document.getElementById('live-preview-confirm-btn');
    if (btn) {
        btn.disabled = false;
        setLiveBtnLabel(btn, window.__i18n?.['home.start_stream'] || '● Начать стрим');
    }
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    startLivePreviewCamera();
}

function closeLiveStartPreview(stopCamera) {
    const modal = document.getElementById('live-start-preview-modal');
    if (modal) modal.classList.add('hidden');
    const previewCam = document.getElementById('live-preview-cam');
    if (previewCam) previewCam.srcObject = null;

    const setup = document.getElementById('live-setup-modal');
    const setupOpen = setup && !setup.classList.contains('hidden');
    if (!setupOpen) {
        if (!document.getElementById('stream-viewer') || document.getElementById('stream-viewer').classList.contains('hidden')) {
            document.body.style.overflow = '';
        }
        if (stopCamera !== false && !window.__myLiveId) {
            stopLiveCamera();
        }
    } else if (stopCamera !== false && !window.__myLiveId) {
        // остаёмся в настройках — камеру можно отпустить
        stopLiveCamera();
    }
    liveStartConfirmBusy = false;
}

function startLivePreviewCamera() {
    const cam = document.getElementById('live-preview-cam');
    const placeholder = document.getElementById('live-preview-placeholder');
    const status = document.getElementById('live-preview-status');
    if (!cam) return;

    const showCam = function (stream) {
        if (!stream) return;
        cam.srcObject = stream;
        cam.muted = true;
        cam.play().catch(function () {});
        if (placeholder) placeholder.classList.add('hidden');
    };

    const showError = function () {
        if (placeholder) placeholder.classList.remove('hidden');
        if (status) {
            status.textContent = window.__i18n?.['home.live_preview_cam_error']
                || 'Камера недоступна. Можно начать эфир — зрители увидят заглушку.';
        }
    };

    if (liveMediaStream) {
        showCam(liveMediaStream);
        return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
        showError();
        return;
    }
    if (status) {
        status.textContent = window.__i18n?.['home.live_preview_waiting'] || 'Подключаем камеру…';
    }
    if (placeholder) placeholder.classList.remove('hidden');

    const attach = function (stream) {
        if (stream) showCam(stream);
        else showError();
    };

    if (liveMediaPromise) {
        liveMediaPromise.then(attach).catch(showError);
        return;
    }

    liveMediaPromise = Promise.all([
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }),
        navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            },
            video: false
        }).catch(function () { return null; })
    ])
        .then(function (parts) {
            const videoStream = parts[0];
            const audioStream = parts[1];
            const tracks = videoStream.getVideoTracks().slice();
            if (audioStream) {
                audioStream.getAudioTracks().forEach(function (t) {
                    t.enabled = true;
                    tracks.push(t);
                });
            }
            const stream = new MediaStream(tracks);
            liveMediaStream = stream;
            attach(stream);
            return stream;
        })
        .catch(function () {
            showError();
            return null;
        })
        .finally(function () {
            liveMediaPromise = null;
        });
}

function buildLiveSetupPayload() {
    syncLiveSetupFromForm();
    return {
        product_ids: liveSetupState.products.map(function (p) { return p.id; }),
        featured_product_id: liveSetupState.featuredId || null,
        featured_price: liveSetupState.featuredPrice,
        duration: liveSetupState.duration,
        visibility: liveSetupState.visibility,
        chat_enabled: liveSetupState.chatEnabled,
        notify_subs: liveSetupState.notifySubs,
        giveaway: liveSetupState.giveaway
    };
}

function confirmStartLiveStream() {
    if (!window.__currentUserId) {
        alert(window.__i18n?.['js.login_to_stream'] || 'Войдите, чтобы начать эфир');
        return;
    }
    if (liveStartConfirmBusy || window.__myLiveId) return;

    const payload = buildLiveSetupPayload();
    if (!payload.product_ids.length) {
        alert(window.__i18n?.['home.live_setup_need_product'] || 'Добавьте минимум 1 товар');
        closeLiveStartPreview(true);
        return;
    }

    liveStartConfirmBusy = true;
    const btn = document.getElementById('live-preview-confirm-btn');
    if (btn) {
        btn.disabled = true;
        setLiveBtnLabel(btn, window.__i18n?.['home.live_preview_starting'] || 'Запуск…');
    }

    const body = new FormData();
    body.append('setup', JSON.stringify(payload));
    if (liveSetupState.coverFile) {
        body.append('cover', liveSetupState.coverFile);
    }

    fetch(window.__streamLiveStart, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: body
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) {
                liveStartConfirmBusy = false;
                if (btn) {
                    btn.disabled = false;
                    setLiveBtnLabel(btn, window.__i18n?.['home.start_stream'] || '● Начать стрим');
                }
                alert(data.message || window.__i18n?.['js.stream_fail'] || 'Не удалось начать эфир');
                return;
            }
            try { localStorage.removeItem(LIVE_SETUP_DRAFT_KEY); } catch (e) { /* ignore */ }
            closeLiveStartPreview(false);
            closeLiveSetup();
            window.__myLiveId = data.id;
            startLiveHeartbeat(data.id);
            startLiveCameraPreview();

            const hostName = document.getElementById('live-setup-host-name');
            const me = {
                id: data.id,
                user_id: window.__currentUserId,
                title: data.title,
                description: window.__i18n?.['js.stream_desc'] || 'Прямой эфир — не сохраняется',
                author_name: (hostName && hostName.textContent) || window.__i18n?.['js.you'] || 'Вы',
                author_avatar: '●',
                is_live: true,
                file: null,
                url: null,
                embed: null,
                cover: data.cover || null
            };
            window.__streams = window.__streams || [];
            window.__streams.unshift(me);
            openStreamViewer(0);
            liveStartConfirmBusy = false;
        })
        .catch(function () {
            liveStartConfirmBusy = false;
            if (btn) {
                btn.disabled = false;
                setLiveBtnLabel(btn, window.__i18n?.['home.start_stream'] || '● Начать стрим');
            }
            alert(window.__i18n?.['js.stream_error'] || 'Ошибка старта эфира');
        });
}

function fetchLiveSetupCatalog() {
    if (!window.__streamLiveMyProducts) return;
    fetch(window.__streamLiveMyProducts, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.ok) return;
            liveSetupState.catalog = data.products || [];
            // синхронизируем выбранные с актуальным каталогом
            const byId = {};
            liveSetupState.catalog.forEach(function (p) { byId[p.id] = p; });
            liveSetupState.products = liveSetupState.products
                .map(function (p) { return byId[p.id] || p; })
                .filter(function (p) { return byId[p.id]; });
            if (liveSetupState.featuredId && !byId[liveSetupState.featuredId]) {
                liveSetupState.featuredId = 0;
                liveSetupState.featuredPrice = null;
            }
            renderLiveSetup();
        })
        .catch(function () {});
}

function syncLiveSetupFromForm() {
    const duration = document.getElementById('live-setup-duration');
    const visibility = document.getElementById('live-setup-visibility');
    const chat = document.getElementById('live-setup-chat');
    if (duration) liveSetupState.duration = Number(duration.value) || 7200;
    if (visibility) liveSetupState.visibility = visibility.value || 'all';
    if (chat) liveSetupState.chatEnabled = chat.value !== '0';
}

function toggleLiveSetupNotify() {
    liveSetupState.notifySubs = !liveSetupState.notifySubs;
    const btn = document.getElementById('live-setup-notify-toggle');
    if (btn) {
        btn.classList.toggle('is-on', liveSetupState.notifySubs);
        btn.setAttribute('aria-checked', liveSetupState.notifySubs ? 'true' : 'false');
    }
}

function onLiveSetupCoverChange(e) {
    const file = e.target && e.target.files && e.target.files[0];
    if (!file) return;
    if (liveSetupState.coverUrl) URL.revokeObjectURL(liveSetupState.coverUrl);
    liveSetupState.coverFile = file;
    liveSetupState.coverUrl = URL.createObjectURL(file);
    const wrap = document.getElementById('live-setup-cover-preview');
    const img = document.getElementById('live-setup-cover-img');
    if (img) img.src = liveSetupState.coverUrl;
    if (wrap) wrap.classList.remove('hidden');
}

function clearLiveSetupCover() {
    if (liveSetupState.coverUrl) URL.revokeObjectURL(liveSetupState.coverUrl);
    liveSetupState.coverFile = null;
    liveSetupState.coverUrl = null;
    const input = document.getElementById('live-setup-cover-input');
    if (input) input.value = '';
    const wrap = document.getElementById('live-setup-cover-preview');
    if (wrap) wrap.classList.add('hidden');
}

function saveLiveSetupDraft() {
    syncLiveSetupFromForm();
    const draft = {
        product_ids: liveSetupState.products.map(function (p) { return p.id; }),
        featuredId: liveSetupState.featuredId,
        featuredPrice: liveSetupState.featuredPrice,
        giveaway: liveSetupState.giveaway,
        duration: liveSetupState.duration,
        visibility: liveSetupState.visibility,
        chatEnabled: liveSetupState.chatEnabled,
        notifySubs: liveSetupState.notifySubs
    };
    try {
        localStorage.setItem(LIVE_SETUP_DRAFT_KEY, JSON.stringify(draft));
        alert(window.__i18n?.['home.live_setup_draft_saved'] || 'Черновик сохранён');
    } catch (e) {
        alert(window.__i18n?.['home.live_setup_draft_saved'] || 'Черновик сохранён');
    }
}

function loadLiveSetupDraft() {
    let draft = null;
    try {
        draft = JSON.parse(localStorage.getItem(LIVE_SETUP_DRAFT_KEY) || 'null');
    } catch (e) {
        draft = null;
    }
    if (!draft || typeof draft !== 'object') return;
    liveSetupState.featuredId = Number(draft.featuredId) || 0;
    liveSetupState.featuredPrice = draft.featuredPrice != null ? Number(draft.featuredPrice) : null;
    liveSetupState.giveaway = draft.giveaway || null;
    liveSetupState.duration = Number(draft.duration) || 7200;
    liveSetupState.visibility = draft.visibility || 'all';
    liveSetupState.chatEnabled = draft.chatEnabled !== false;
    liveSetupState.notifySubs = draft.notifySubs !== false;
    liveSetupState._draftProductIds = Array.isArray(draft.product_ids) ? draft.product_ids.map(Number) : [];
}

function renderLiveSetup() {
    const duration = document.getElementById('live-setup-duration');
    const visibility = document.getElementById('live-setup-visibility');
    const chat = document.getElementById('live-setup-chat');
    const notify = document.getElementById('live-setup-notify-toggle');
    if (duration) duration.value = String(liveSetupState.duration);
    if (visibility) visibility.value = liveSetupState.visibility;
    if (chat) chat.value = liveSetupState.chatEnabled ? '1' : '0';
    if (notify) {
        notify.classList.toggle('is-on', liveSetupState.notifySubs);
        notify.setAttribute('aria-checked', liveSetupState.notifySubs ? 'true' : 'false');
    }

    // восстановить товары из черновика после загрузки каталога
    if (liveSetupState._draftProductIds && liveSetupState._draftProductIds.length && liveSetupState.catalog.length) {
        const byId = {};
        liveSetupState.catalog.forEach(function (p) { byId[p.id] = p; });
        liveSetupState.products = liveSetupState._draftProductIds
            .map(function (id) { return byId[id]; })
            .filter(Boolean);
        liveSetupState._draftProductIds = null;
    }

    const list = document.getElementById('live-setup-products-list');
    if (list) {
        list.innerHTML = liveSetupState.products.map(function (p) {
            const img = p.image
                ? 'background-image:url(\'' + String(p.image).replace(/'/g, '\\\'') + '\')'
                : '';
            return '<div class="live-setup-product-row" data-id="' + p.id + '">'
                + '<span class="thumb" style="' + img + '"></span>'
                + '<div class="meta"><p class="title line-clamp-2">' + escapeLiveHtml(p.title) + '</p>'
                + '<p class="price">' + escapeLiveHtml(p.price_label || '') + '</p></div>'
                + '<div class="actions">'
                + '<button type="button" class="live-setup-icon-btn" onclick="removeLiveSetupProduct(' + p.id + ')" aria-label="Delete">🗑</button>'
                + '</div></div>';
        }).join('');
    }

    const podEmpty = document.getElementById('live-setup-pod-empty');
    const podCard = document.getElementById('live-setup-pod-card');
    const featured = liveSetupState.products.find(function (p) { return p.id === liveSetupState.featuredId; })
        || liveSetupState.catalog.find(function (p) { return p.id === liveSetupState.featuredId; });
    if (featured && liveSetupState.featuredId) {
        if (podEmpty) podEmpty.classList.add('hidden');
        if (podCard) {
            podCard.classList.remove('hidden');
            const priceLabel = liveSetupState.featuredPrice != null
                ? (Number(liveSetupState.featuredPrice).toLocaleString('ru-RU') + ' ₸')
                : (featured.price_label || '');
            const img = featured.image
                ? 'background-image:url(\'' + String(featured.image).replace(/'/g, '\\\'') + '\')'
                : '';
            podCard.innerHTML = '<div class="live-setup-product-row">'
                + '<span class="thumb" style="' + img + '"></span>'
                + '<div class="meta"><p class="title line-clamp-2">' + escapeLiveHtml(featured.title) + '</p>'
                + '<p class="price">' + escapeLiveHtml(priceLabel) + '</p></div>'
                + '<div class="actions">'
                + '<button type="button" class="live-setup-icon-btn" onclick="clearLiveSetupPod()" aria-label="Delete">🗑</button>'
                + '</div></div>';
        }
    } else {
        if (podEmpty) podEmpty.classList.remove('hidden');
        if (podCard) {
            podCard.classList.add('hidden');
            podCard.innerHTML = '';
        }
    }

    const giveEmpty = document.getElementById('live-setup-give-empty');
    const giveCard = document.getElementById('live-setup-give-card');
    if (liveSetupState.giveaway && liveSetupState.giveaway.title) {
        if (giveEmpty) giveEmpty.classList.add('hidden');
        if (giveCard) {
            giveCard.classList.remove('hidden');
            giveCard.innerHTML = '<p class="text-[12px] font-bold">' + escapeLiveHtml(liveSetupState.giveaway.title) + '</p>'
                + '<p class="text-[11px] text-gray-500 mt-1">♥ ' + (liveSetupState.giveaway.goal || 500) + '</p>'
                + '<button type="button" class="mt-2 text-[11px] font-semibold text-[#7c3aed]" onclick="openLiveGiveawayEditor()">✎</button>';
        }
    } else {
        if (giveEmpty) giveEmpty.classList.remove('hidden');
        if (giveCard) {
            giveCard.classList.add('hidden');
            giveCard.innerHTML = '';
        }
    }
}

function escapeLiveHtml(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function removeLiveSetupProduct(id) {
    liveSetupState.products = liveSetupState.products.filter(function (p) { return p.id !== id; });
    if (liveSetupState.featuredId === id) {
        liveSetupState.featuredId = 0;
        liveSetupState.featuredPrice = null;
    }
    renderLiveSetup();
}

function clearLiveSetupPod() {
    liveSetupState.featuredId = 0;
    liveSetupState.featuredPrice = null;
    renderLiveSetup();
}

function openLiveProductPicker(mode) {
    livePickerMode = mode === 'pod' ? 'pod' : 'products';
    livePickerSelected = {};
    if (livePickerMode === 'products') {
        liveSetupState.products.forEach(function (p) { livePickerSelected[p.id] = true; });
    } else if (liveSetupState.featuredId) {
        livePickerSelected[liveSetupState.featuredId] = true;
    }
    const title = document.getElementById('live-picker-title');
    if (title) {
        title.textContent = livePickerMode === 'pod'
            ? (window.__i18n?.['home.live_setup_add_pod'] || 'Добавить товар дня')
            : (window.__i18n?.['home.live_setup_add_product'] || 'Добавить товар');
    }
    const priceWrap = document.getElementById('live-picker-pod-price');
    if (priceWrap) priceWrap.classList.toggle('hidden', livePickerMode !== 'pod');
    const priceInput = document.getElementById('live-picker-price-input');
    if (priceInput) {
        priceInput.value = liveSetupState.featuredPrice != null ? String(liveSetupState.featuredPrice) : '';
    }
    renderLiveProductPicker();
    document.getElementById('live-product-picker')?.classList.remove('hidden');
}

function closeLiveProductPicker() {
    document.getElementById('live-product-picker')?.classList.add('hidden');
}

function renderLiveProductPicker() {
    const list = document.getElementById('live-picker-list');
    if (!list) return;
    const source = livePickerMode === 'pod' && liveSetupState.products.length
        ? liveSetupState.products
        : liveSetupState.catalog;
    if (!source.length) {
        list.innerHTML = '<p class="text-xs text-gray-400 text-center py-8">'
            + escapeLiveHtml(window.__i18n?.['home.live_setup_no_products'] || 'Нет активных товаров')
            + '</p>';
        return;
    }
    list.innerHTML = source.map(function (p) {
        const selected = !!livePickerSelected[p.id];
        const img = p.image
            ? 'background-image:url(\'' + String(p.image).replace(/'/g, '\\\'') + '\')'
            : '';
        return '<button type="button" class="live-picker-item' + (selected ? ' is-selected' : '') + '" onclick="toggleLivePickerItem(' + p.id + ')">'
            + '<span class="thumb" style="' + img + '"></span>'
            + '<span class="min-w-0 flex-1 text-left"><span class="block text-[12px] font-semibold line-clamp-2">' + escapeLiveHtml(p.title) + '</span>'
            + '<span class="block text-[12px] font-bold text-[#7c3aed] mt-0.5">' + escapeLiveHtml(p.price_label || '') + '</span></span>'
            + '<span class="text-[#7c3aed] font-bold">' + (selected ? '✓' : '') + '</span></button>';
    }).join('');
}

function toggleLivePickerItem(id) {
    if (livePickerMode === 'pod') {
        livePickerSelected = {};
        livePickerSelected[id] = true;
    } else if (livePickerSelected[id]) {
        delete livePickerSelected[id];
    } else {
        livePickerSelected[id] = true;
    }
    renderLiveProductPicker();
}

function confirmLiveProductPicker() {
    const byId = {};
    liveSetupState.catalog.forEach(function (p) { byId[p.id] = p; });
    liveSetupState.products.forEach(function (p) { byId[p.id] = p; });

    if (livePickerMode === 'pod') {
        const id = Number(Object.keys(livePickerSelected)[0] || 0);
        if (!id) {
            closeLiveProductPicker();
            return;
        }
        const product = byId[id];
        if (product && !liveSetupState.products.some(function (p) { return p.id === id; })) {
            liveSetupState.products.push(product);
        }
        liveSetupState.featuredId = id;
        const priceInput = document.getElementById('live-picker-price-input');
        const val = priceInput && priceInput.value !== '' ? Number(priceInput.value) : null;
        liveSetupState.featuredPrice = (val != null && !isNaN(val) && val >= 0) ? val : null;
    } else {
        liveSetupState.products = Object.keys(livePickerSelected)
            .map(function (id) { return byId[Number(id)]; })
            .filter(Boolean);
        if (liveSetupState.featuredId && !liveSetupState.products.some(function (p) { return p.id === liveSetupState.featuredId; })) {
            liveSetupState.featuredId = 0;
            liveSetupState.featuredPrice = null;
        }
    }
    closeLiveProductPicker();
    renderLiveSetup();
}

function openLiveGiveawayEditor() {
    const title = document.getElementById('live-give-title-input');
    const goal = document.getElementById('live-give-goal-input');
    if (title) title.value = (liveSetupState.giveaway && liveSetupState.giveaway.title) || '';
    if (goal) goal.value = String((liveSetupState.giveaway && liveSetupState.giveaway.goal) || 500);
    document.getElementById('live-giveaway-editor')?.classList.remove('hidden');
}

function closeLiveGiveawayEditor() {
    document.getElementById('live-giveaway-editor')?.classList.add('hidden');
}

function confirmLiveGiveawayEditor() {
    const title = (document.getElementById('live-give-title-input')?.value || '').trim();
    const goal = Number(document.getElementById('live-give-goal-input')?.value || 500);
    if (!title) {
        liveSetupState.giveaway = null;
    } else {
        liveSetupState.giveaway = {
            title: title.slice(0, 120),
            goal: Math.max(50, Math.min(5000, goal || 500))
        };
    }
    closeLiveGiveawayEditor();
    renderLiveSetup();
}

function clearLiveGiveaway() {
    liveSetupState.giveaway = null;
    closeLiveGiveawayEditor();
    renderLiveSetup();
}

function startLiveHeartbeat(id) {
    clearInterval(liveHeartbeatTimer);
    const ping = function () {
        const body = new FormData();
        body.append('id', id);
        fetch(window.__streamLiveHeartbeat, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).catch(function () {});
    };
    ping();
    liveHeartbeatTimer = setInterval(ping, 15000);
}

function endLiveStream() {
    const id = window.__myLiveId;
    clearInterval(liveHeartbeatTimer);
    liveHeartbeatTimer = null;
    stopLiveRtc();
    stopLiveCamera();

    const body = new FormData();
    if (id) body.append('id', id);

    fetch(window.__streamLiveEnd, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).finally(function () {
        if (id && window.__streams) {
            window.__streams = window.__streams.filter(function (s) { return Number(s.id) !== Number(id); });
        }
        window.__myLiveId = null;
        closeStreamViewer();
        location.reload();
    });
}

function startLiveCameraPreview() {
    const cam = document.getElementById('stream-live-cam');
    if (!cam || !navigator.mediaDevices?.getUserMedia) {
        const hint = document.getElementById('stream-live-hint');
        if (hint) hint.textContent = window.__i18n?.['js.live_waiting'] || 'Ожидание камеры стримера…';
        if (window.__myLiveId) startLiveHostRtc(window.__myLiveId);
        return;
    }

    const attachLocal = function (stream) {
        if (!stream) return;
        cam.srcObject = stream;
        cam.classList.remove('hidden');
        cam.muted = true;
        cam.play().catch(function () {});
        if (window.__myLiveId) startLiveHostRtc(window.__myLiveId);
        const pending = livePendingPeers.splice(0);
        pending.forEach(function (pid) { createHostOfferForPeer(pid); });
        renegotiateHostPeersWithMedia();
    };

    if (liveMediaStream) {
        attachLocal(liveMediaStream);
        return;
    }
    if (liveMediaPromise) {
        liveMediaPromise.then(attachLocal).catch(function () {
            if (window.__myLiveId) startLiveHostRtc(window.__myLiveId);
        });
        return;
    }

    // Камера и микрофон отдельно — иначе при отказе мика иногда пропадает и звук в SDP
    liveMediaPromise = Promise.all([
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }),
        navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            },
            video: false
        }).catch(function () { return null; })
    ])
        .then(function (parts) {
            const videoStream = parts[0];
            const audioStream = parts[1];
            const tracks = videoStream.getVideoTracks().slice();
            if (audioStream) {
                audioStream.getAudioTracks().forEach(function (t) {
                    t.enabled = true;
                    tracks.push(t);
                });
            }
            const stream = new MediaStream(tracks);
            liveMediaStream = stream;
            if (!stream.getAudioTracks().length) {
                const hint = document.getElementById('stream-live-hint');
                if (hint) {
                    hint.classList.remove('hidden');
                    hint.textContent = 'Нет доступа к микрофону — зрители не услышат вас';
                }
            }
            attachLocal(stream);
            return stream;
        })
        .catch(function () {
            const hint = document.getElementById('stream-live-hint');
            if (hint) hint.textContent = window.__i18n?.['js.live_waiting'] || 'Ожидание камеры стримера…';
            if (window.__myLiveId) startLiveHostRtc(window.__myLiveId);
            return null;
        })
        .finally(function () {
            liveMediaPromise = null;
        });
}

function renegotiateHostPeersWithMedia() {
    if (!liveMediaStream || !window.__myLiveId) return;
    Object.keys(liveHostPcs).forEach(function (peerId) {
        const pc = liveHostPcs[peerId];
        if (!pc) return;
        const senders = pc.getSenders();
        const hasAudio = senders.some(function (s) { return s.track && s.track.kind === 'audio'; });
        const hasVideo = senders.some(function (s) { return s.track && s.track.kind === 'video'; });
        let added = false;
        liveMediaStream.getTracks().forEach(function (track) {
            if (track.kind === 'audio' && !hasAudio) {
                pc.addTrack(track, liveMediaStream);
                added = true;
            }
            if (track.kind === 'video' && !hasVideo) {
                pc.addTrack(track, liveMediaStream);
                added = true;
            }
        });
        if (!hasAudio && !hasVideo) {
            closeHostPeer(peerId);
            createHostOfferForPeer(peerId);
            return;
        }
        if (added) {
            pc.createOffer()
                .then(function (offer) {
                    return pc.setLocalDescription(offer).then(function () { return offer; });
                })
                .then(function (offer) {
                    return livePostSignal('host', window.__myLiveId, peerId, 'offer', { sdp: offer.sdp });
                })
                .catch(function () {});
        }
    });
}

function stopLiveCamera() {
    if (liveMediaStream) {
        liveMediaStream.getTracks().forEach(function (t) { t.stop(); });
        liveMediaStream = null;
    }
    const cam = document.getElementById('stream-live-cam');
    if (cam && !liveViewerPc) {
        cam.srcObject = null;
        cam.classList.add('hidden');
    }
}

function livePostSignal(role, streamId, peerId, type, payload) {
    if (!window.__streamLiveSignal) return Promise.resolve();
    const body = new FormData();
    body.append('role', role);
    body.append('stream_id', String(streamId));
    body.append('peer_id', peerId);
    body.append('type', type);
    if (payload != null) body.append('payload', JSON.stringify(payload));
    return fetch(window.__streamLiveSignal, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json().catch(function () { return {}; }); }).catch(function () { return {}; });
}

function stopLiveSignalPoll() {
    clearInterval(liveSignalPollTimer);
    liveSignalPollTimer = null;
    liveSignalAfterId = 0;
    liveSignalRole = null;
}

function startLiveSignalPoll(role, streamId, peerId) {
    stopLiveSignalPoll();
    liveSignalRole = role;
    liveSignalAfterId = 0;
    const tick = function () {
        if (!window.__streamLiveSignalPoll) return;
        let url = window.__streamLiveSignalPoll
            + '?stream_id=' + encodeURIComponent(streamId)
            + '&role=' + encodeURIComponent(role)
            + '&after=' + encodeURIComponent(liveSignalAfterId);
        if (peerId) url += '&peer_id=' + encodeURIComponent(peerId);
        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.live === false) {
                    if (role === 'viewer') {
                        const hint = document.getElementById('stream-live-hint');
                        if (hint) hint.textContent = window.__i18n?.['js.stream_fail'] || 'Эфир завершён';
                    }
                    return;
                }
                const signals = data.signals || [];
                signals.forEach(function (sig) {
                    if (sig.id > liveSignalAfterId) liveSignalAfterId = sig.id;
                    if (role === 'host') handleHostSignal(sig);
                    else handleViewerSignal(sig);
                });
            })
            .catch(function () {});
    };
    tick();
    liveSignalPollTimer = setInterval(tick, 1000);
}

function startLiveHostRtc(streamId) {
    if (!window.RTCPeerConnection) return;
    if (liveHostStreamId === streamId && liveSignalRole === 'host' && liveSignalPollTimer) return;
    liveHostStreamId = streamId;
    startLiveSignalPoll('host', streamId, null);
}

async function handleHostSignal(sig) {
    const peerId = sig.peer_id;
    if (!peerId) return;

    if (sig.type === 'leave') {
        closeHostPeer(peerId);
        return;
    }

    if (sig.type === 'join') {
        await createHostOfferForPeer(peerId);
        return;
    }

    if (sig.type === 'answer') {
        const pc = liveHostPcs[peerId];
        if (!pc || !sig.payload?.sdp) return;
        try {
            await pc.setRemoteDescription({ type: 'answer', sdp: sig.payload.sdp });
        } catch (e) { /* ignore */ }
        return;
    }

    if (sig.type === 'ice' && sig.payload?.candidate) {
        const pc = liveHostPcs[peerId];
        if (!pc) return;
        try {
            await pc.addIceCandidate(sig.payload.candidate);
        } catch (e) { /* ignore */ }
    }
}

async function createHostOfferForPeer(peerId) {
    if (!window.__myLiveId || liveHostPcs[peerId]) return;

    // ждём камеру/мик, иначе offer уходит без audio
    if (!liveMediaStream && liveMediaPromise) {
        try { await liveMediaPromise; } catch (e) { /* ignore */ }
    }

    if (!liveMediaStream) {
        if (livePendingPeers.indexOf(peerId) === -1) livePendingPeers.push(peerId);
        return;
    }

    const pc = new RTCPeerConnection(LIVE_ICE_SERVERS);
    liveHostPcs[peerId] = pc;

    liveMediaStream.getTracks().forEach(function (track) {
        track.enabled = true;
        pc.addTrack(track, liveMediaStream);
    });

    pc.onicecandidate = function (ev) {
        if (!ev.candidate || !window.__myLiveId) return;
        livePostSignal('host', window.__myLiveId, peerId, 'ice', {
            candidate: ev.candidate.toJSON ? ev.candidate.toJSON() : ev.candidate
        });
    };

    try {
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        await livePostSignal('host', window.__myLiveId, peerId, 'offer', { sdp: offer.sdp });
    } catch (e) {
        closeHostPeer(peerId);
    }
}

function closeHostPeer(peerId) {
    const pc = liveHostPcs[peerId];
    if (pc) {
        try { pc.close(); } catch (e) { /* ignore */ }
        delete liveHostPcs[peerId];
    }
}

function attachLiveViewerMedia(track, inboundStream) {
    const cam = document.getElementById('stream-live-cam');
    const audioEl = document.getElementById('stream-live-audio');
    if (!cam || !track) return;

    track.enabled = true;

    // копим треки в своём MediaStream — иначе audio, пришедший вторым, не слышен
    if (!window.__liveRemoteStream) {
        window.__liveRemoteStream = new MediaStream();
    }
    const remote = window.__liveRemoteStream;
    if (!remote.getTracks().some(function (t) { return t.id === track.id; })) {
        remote.addTrack(track);
    }
    // если браузер отдал готовый stream — подтянем недостающие треки оттуда
    if (inboundStream) {
        inboundStream.getTracks().forEach(function (t) {
            if (!remote.getTracks().some(function (x) { return x.id === t.id; })) {
                t.enabled = true;
                remote.addTrack(t);
            }
        });
    }

    // видео: только video-треки (без эха через video.muted)
    const videoOnly = new MediaStream(remote.getVideoTracks());
    cam.srcObject = videoOnly;
    cam.classList.remove('hidden');
    cam.muted = true;
    cam.play().catch(function () {});

    // звук: отдельный <audio> — так надёжнее, чем через <video>
    if (audioEl) {
        const audioTracks = remote.getAudioTracks();
        if (audioTracks.length) {
            const audioOnly = new MediaStream(audioTracks);
            audioEl.srcObject = audioOnly;
            audioEl.muted = streamMuted;
            audioEl.volume = 1;
            audioEl.play().catch(function () {
                streamMuted = true;
                audioEl.muted = true;
                updateMuteBtn();
                showLiveUnmuteBtn(true);
                audioEl.play().catch(function () {});
            });
            if (streamMuted) showLiveUnmuteBtn(true);
        }
    }

    const hint = document.getElementById('stream-live-hint');
    if (hint) hint.classList.add('hidden');
    const av = document.getElementById('stream-live-avatar');
    if (av) av.classList.add('hidden');
}

function startLiveViewerRtc(streamId) {
    if (!window.RTCPeerConnection || !window.__streamLiveSignal) return;
    if (liveViewerStreamId === streamId && liveViewerPc) return;

    stopLiveRtcViewerOnly();

    liveViewerPeerId = liveRandomPeerId();
    liveViewerStreamId = streamId;
    window.__liveRemoteStream = new MediaStream();
    liveViewerPc = new RTCPeerConnection(LIVE_ICE_SERVERS);

    liveViewerPc.ontrack = function (ev) {
        attachLiveViewerMedia(ev.track, ev.streams && ev.streams[0] ? ev.streams[0] : null);
    };

    liveViewerPc.onicecandidate = function (ev) {
        if (!ev.candidate || !liveViewerPeerId || !liveViewerStreamId) return;
        livePostSignal('viewer', liveViewerStreamId, liveViewerPeerId, 'ice', {
            candidate: ev.candidate.toJSON ? ev.candidate.toJSON() : ev.candidate
        });
    };

    // старт без звука + явная кнопка (автоплей со звуком блокируется)
    streamMuted = true;
    updateMuteBtn();
    showLiveUnmuteBtn(true);

    startLiveSignalPoll('viewer', streamId, liveViewerPeerId);
    livePostSignal('viewer', streamId, liveViewerPeerId, 'join', null);
}

function showLiveUnmuteBtn(show) {
    const btn = document.getElementById('stream-live-unmute');
    if (!btn) return;
    btn.classList.toggle('hidden', !show || !!window.__myLiveId);
}

function unmuteLiveStream() {
    streamMuted = false;
    const cam = document.getElementById('stream-live-cam');
    const audioEl = document.getElementById('stream-live-audio');
    const video = document.getElementById('stream-video');

    if (audioEl && !window.__myLiveId) {
        audioEl.muted = false;
        audioEl.volume = 1;
        if (audioEl.srcObject instanceof MediaStream) {
            audioEl.srcObject.getAudioTracks().forEach(function (t) { t.enabled = true; });
        }
        const p = audioEl.play();
        if (p && p.catch) p.catch(function () {});
    }
    if (cam && !window.__myLiveId) {
        // видео остаётся mute — звук идёт с <audio>
        cam.muted = true;
        cam.play().catch(function () {});
    }
    if (video) {
        video.muted = false;
        video.play().catch(function () {});
    }
    showLiveUnmuteBtn(false);
    updateMuteBtn();
}

async function handleViewerSignal(sig) {
    if (!liveViewerPc) return;

    if (sig.type === 'offer' && sig.payload?.sdp) {
        try {
            const offer = { type: 'offer', sdp: sig.payload.sdp };
            if (liveViewerPc.signalingState === 'have-local-offer') {
                await liveViewerPc.setLocalDescription({ type: 'rollback' }).catch(function () {});
            }
            await liveViewerPc.setRemoteDescription(offer);
            const answer = await liveViewerPc.createAnswer();
            await liveViewerPc.setLocalDescription(answer);
            await livePostSignal('viewer', liveViewerStreamId, liveViewerPeerId, 'answer', { sdp: answer.sdp });
        } catch (e) { /* ignore */ }
        return;
    }

    if (sig.type === 'ice' && sig.payload?.candidate) {
        try {
            await liveViewerPc.addIceCandidate(sig.payload.candidate);
        } catch (e) { /* ignore */ }
    }
}

function stopLiveRtcViewerOnly() {
    if (liveViewerPeerId && liveViewerStreamId) {
        livePostSignal('viewer', liveViewerStreamId, liveViewerPeerId, 'leave', null);
    }
    if (liveViewerPc) {
        try { liveViewerPc.close(); } catch (e) { /* ignore */ }
        liveViewerPc = null;
    }
    liveViewerPeerId = null;
    liveViewerStreamId = null;
    window.__liveRemoteStream = null;
    showLiveUnmuteBtn(false);

    const cam = document.getElementById('stream-live-cam');
    const audioEl = document.getElementById('stream-live-audio');
    if (cam && !window.__myLiveId) {
        cam.srcObject = null;
        cam.classList.add('hidden');
    }
    if (audioEl) {
        audioEl.pause();
        audioEl.srcObject = null;
    }
    const hint = document.getElementById('stream-live-hint');
    if (hint) hint.classList.remove('hidden');
    const av = document.getElementById('stream-live-avatar');
    if (av) av.classList.remove('hidden');
}

function stopLiveRtc() {
    stopLiveSignalPoll();
    Object.keys(liveHostPcs).forEach(closeHostPeer);
    liveHostPcs = {};
    liveHostStreamId = null;
    livePendingPeers = [];
    stopLiveRtcViewerOnly();
}

/* ===== Live Shopping UI ===== */
let liveShopStreamId = null;
let liveShopViewerKey = null;
let liveShopPollTimer = null;
let liveShopCommentTimer = null;
let liveShopCommentAfter = 0;
let liveShopElapsedBase = 0;
let liveShopElapsedTick = null;
let liveShopIsHost = false;
let liveShopHostId = 0;
let liveShopGiveJoined = false;
let liveShopGiveCount = 0;
let liveShopGiveGoal = 500;

function liveShopEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function liveShopFormatElapsed(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    const h = String(Math.floor(sec / 3600)).padStart(2, '0');
    const m = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
    const s = String(sec % 60).padStart(2, '0');
    return h + ':' + m + ':' + s;
}

function liveShopFormatLikes(n) {
    n = Number(n) || 0;
    if (n >= 1000) return (n / 1000).toFixed(n >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'K';
    return String(n);
}

function startLiveShop(stream) {
    const ui = document.getElementById('live-shop-ui');
    if (!ui || !stream?.id) return;
    stopLiveShop();

    liveShopStreamId = stream.id;
    liveShopIsHost = Number(stream.user_id) === Number(window.__currentUserId);
    liveShopHostId = Number(stream.user_id) || 0;
    liveShopViewerKey = liveRandomPeerId();
    liveShopCommentAfter = 0;
    liveShopElapsedBase = 0;
    liveShopGiveJoined = false;
    liveShopGiveGoal = 500;

    ui.classList.remove('hidden');
    document.body.classList.add('live-stream-open');
    try { toggleAiAssistant(false); } catch (e) { /* ignore */ }

    document.getElementById('live-shop-avatar').textContent = stream.author_avatar || '?';
    const nameEl = document.getElementById('live-shop-name');
    if (nameEl) {
        nameEl.textContent = stream.author_name || '';
        if (liveShopHostId > 0) {
            nameEl.classList.add('cursor-pointer', 'hover:underline');
            nameEl.onclick = function () {
                openSellerProfile(liveShopHostId);
            };
        } else {
            nameEl.classList.remove('cursor-pointer', 'hover:underline');
            nameEl.onclick = null;
        }
    }
    document.getElementById('live-shop-comments').innerHTML = '';
    document.getElementById('live-shop-viewers').textContent = '1';
    document.getElementById('live-shop-likes').textContent = '0';
    const ht = document.getElementById('live-shop-hearts-total');
    if (ht) ht.textContent = '0';
    document.getElementById('live-shop-timer').textContent = '00:00:00';
    const followers = document.getElementById('live-shop-followers');
    if (followers) {
        followers.textContent = '— ' + (window.__i18n?.['live.followers'] || 'подписчиков');
    }
    updateLiveShopFollowBtn(false, 0, true);
    closeLiveShopShelf();
    const prodBadge = document.getElementById('live-shop-products-badge');
    if (prodBadge) {
        prodBadge.textContent = '0';
        prodBadge.classList.add('hidden');
    }

    const input = document.getElementById('live-shop-comment-input');
    if (input) {
        input.disabled = !window.__currentUserId;
        input.placeholder = window.__currentUserId
            ? (window.__i18n?.['live.comment_placeholder'] || 'Напишите комментарий…')
            : (window.__i18n?.['live.login_to_comment'] || 'Войдите, чтобы комментировать');
        input.onfocus = function () {
            document.getElementById('live-shop-ui')?.classList.add('is-composing');
        };
        input.onblur = function () {
            // delay so tap on buy/end still works
            setTimeout(function () {
                document.getElementById('live-shop-ui')?.classList.remove('is-composing');
            }, 180);
        };
    }

    if (window.__cartCount != null) updateLiveShopCartBadge(window.__cartCount);

    pollLiveShop();
    pollLiveComments();
    liveShopPollTimer = setInterval(pollLiveShop, 4000);
    liveShopCommentTimer = setInterval(pollLiveComments, 2500);
    liveShopElapsedTick = setInterval(function () {
        liveShopElapsedBase += 1;
        const el = document.getElementById('live-shop-timer');
        if (el) el.textContent = liveShopFormatElapsed(liveShopElapsedBase);
    }, 1000);
}

function stopLiveShop() {
    clearInterval(liveShopPollTimer);
    clearInterval(liveShopCommentTimer);
    clearInterval(liveShopElapsedTick);
    liveShopPollTimer = null;
    liveShopCommentTimer = null;
    liveShopElapsedTick = null;
    liveShopStreamId = null;
    liveShopHostId = 0;
    document.getElementById('live-shop-ui')?.classList.add('hidden');
    document.getElementById('live-shop-featured')?.classList.add('hidden');
    document.getElementById('live-shop-shelf-wrap')?.classList.add('hidden');
    if (typeof closeLiveShopShelf === 'function') closeLiveShopShelf();
    document.getElementById('live-shop-ui')?.classList.remove('is-composing');
    document.body.classList.remove('live-stream-open');
}

function pollLiveShop() {
    if (!liveShopStreamId || !window.__streamLiveShop) return;
    const url = window.__streamLiveShop
        + '?stream_id=' + encodeURIComponent(liveShopStreamId)
        + '&viewer_key=' + encodeURIComponent(liveShopViewerKey || '');
    fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.ok) return;
            if (typeof data.elapsed === 'number') liveShopElapsedBase = data.elapsed;
            const v = document.getElementById('live-shop-viewers');
            const l = document.getElementById('live-shop-likes');
            const ht = document.getElementById('live-shop-hearts-total');
            if (v) v.textContent = String(data.viewers || 0);
            if (l) l.textContent = liveShopFormatLikes(data.likes || 0);
            if (ht) ht.textContent = liveShopFormatLikes(data.likes || 0);
            updateLiveGiveawayProgress(data.likes || 0, data.giveaway);
            applyLiveShopChatEnabled(data.chat_enabled !== false);
            renderLiveShopFeatured(data.featured, data.featured_id);
            renderLiveShopShelf(data.products || [], data.featured_id);
            applyLiveShopGiveaway(data.giveaway);
            if (data.host_id) liveShopHostId = Number(data.host_id) || liveShopHostId;
            updateLiveShopFollowBtn(!!data.is_following, data.followers_count || 0, false);
        })
        .catch(function () {});
}

function updateLiveShopFollowBtn(isFollowing, followersCount, resetOnly) {
    const btn = document.getElementById('live-shop-follow');
    const followers = document.getElementById('live-shop-followers');
    if (followers && !resetOnly) {
        const n = Number(followersCount) || 0;
        followers.textContent = n + ' ' + (window.__i18n?.['live.followers'] || 'подписчиков');
    }
    if (!btn) return;
    if (liveShopIsHost || !liveShopHostId) {
        btn.classList.add('hidden');
        return;
    }
    btn.classList.remove('hidden');
    btn.setAttribute('data-user-id', String(liveShopHostId));
    btn.dataset.userId = String(liveShopHostId);
    setFollowButtonState(btn, !!isFollowing);
}

function renderLiveShopFeatured(product, featuredId) {
    const box = document.getElementById('live-shop-featured');
    if (!box) return;
    if (!product) {
        box.classList.add('hidden');
        return;
    }
    box.classList.remove('hidden');
    const img = document.getElementById('live-shop-feat-img');
    const title = document.getElementById('live-shop-feat-title');
    const price = document.getElementById('live-shop-feat-price');
    const oldP = document.getElementById('live-shop-feat-old');
    const buy = document.getElementById('live-shop-feat-buy');
    if (img) {
        if (product.image) {
            img.style.backgroundImage = 'url("' + String(product.image).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '")';
            img.textContent = '';
        } else {
            img.style.backgroundImage = '';
            img.textContent = '•';
        }
    }
    if (title) title.textContent = product.title || '';
    if (price) price.textContent = product.price_label || '';
    if (oldP) {
        if (product.old_price_label) {
            oldP.textContent = product.old_price_label;
            oldP.classList.remove('hidden');
        } else {
            oldP.classList.add('hidden');
        }
    }
    const stockWrap = document.getElementById('live-shop-feat-stock-wrap');
    if (stockWrap) {
        if (product.stock != null && Number(product.stock) > 0) {
            stockWrap.classList.remove('hidden');
            const st = document.getElementById('live-shop-feat-stock');
            const bar = document.getElementById('live-shop-feat-stock-bar');
            if (st) st.textContent = String(product.stock) + ' шт.';
            if (bar) bar.style.width = Math.min(100, Math.max(8, Number(product.stock) * 8)) + '%';
        } else {
            stockWrap.classList.add('hidden');
        }
    }
    if (buy) {
        liveSheetProductCache = product;
        buy.onclick = function (e) {
            e.preventDefault();
            openLiveProductSheet(product);
        };
    }
}

let liveSheetProduct = null;
let liveSheetProductCache = null;

function openLiveProductFromFeatured() {
    if (liveSheetProductCache) openLiveProductSheet(liveSheetProductCache);
}

function openLiveProductSheet(product) {
    if (!product || !product.id) return;
    liveSheetProduct = product;
    liveSheetProductCache = product;
    const sheet = document.getElementById('live-product-sheet');
    if (!sheet) return;
    const img = document.getElementById('live-sheet-img');
    const title = document.getElementById('live-sheet-title');
    const price = document.getElementById('live-sheet-price');
    const status = document.getElementById('live-sheet-status');
    if (img) {
        if (product.image) {
            img.style.backgroundImage = 'url("' + String(product.image).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '")';
            img.textContent = '';
        } else {
            img.style.backgroundImage = '';
            img.textContent = '•';
        }
    }
    if (title) title.textContent = product.title || '';
    if (price) price.textContent = product.price_label || '';
    if (status) {
        status.classList.add('hidden');
        status.textContent = '';
    }
    sheet.classList.remove('hidden');
    rememberLiveStreamReturn();
}

function closeLiveProductSheet() {
    document.getElementById('live-product-sheet')?.classList.add('hidden');
    liveSheetProduct = null;
}

function rememberLiveStreamReturn() {
    if (!liveShopStreamId) return;
    try {
        sessionStorage.setItem('liveReturnStreamId', String(liveShopStreamId));
        sessionStorage.setItem('liveReturnAt', String(Date.now()));
    } catch (e) { /* ignore */ }
    updateLiveReturnFab();
}

function clearLiveStreamReturn() {
    try {
        sessionStorage.removeItem('liveReturnStreamId');
        sessionStorage.removeItem('liveReturnAt');
    } catch (e) { /* ignore */ }
    updateLiveReturnFab();
}

function updateLiveReturnFab() {
    const fab = document.getElementById('live-return-fab');
    if (!fab) return;
    let id = null;
    let at = 0;
    try {
        id = sessionStorage.getItem('liveReturnStreamId');
        at = parseInt(sessionStorage.getItem('liveReturnAt') || '0', 10);
    } catch (e) { /* ignore */ }
    const inStream = document.body.classList.contains('live-stream-open');
    const fresh = id && at && (Date.now() - at) < 2 * 60 * 60 * 1000;
    fab.classList.toggle('is-on', !!(fresh && !inStream));
}

function returnToLiveStream() {
    let id = null;
    try { id = sessionStorage.getItem('liveReturnStreamId'); } catch (e) { /* ignore */ }
    if (!id) {
        location.href = (window.__homeUrl || '/');
        return;
    }
    const streams = window.__streams || [];
    const idx = streams.findIndex(function (s) { return Number(s.id) === Number(id); });
    if (idx >= 0 && typeof openStreamViewer === 'function') {
        openStreamViewer(idx);
        updateLiveReturnFab();
        return;
    }
    const home = window.__homeUrl || '/';
    const join = home.indexOf('?') >= 0 ? '&' : '?';
    // hash is better
    location.href = home.replace(/#.*$/, '') + '#resume-live=' + encodeURIComponent(id);
}

function liveSheetAddToCart() {
    if (!liveSheetProduct) return;
    if (!window.__currentUserId) {
        rememberLiveStreamReturn();
        location.href = window.__loginUrl || '/login';
        return;
    }
    const base = window.__cartToggleBase || '/cart/';
    const doToggle = function () {
        return fetch(base + liveSheetProduct.id + '/toggle', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); });
    };
    doToggle()
        .then(function (data) {
            if (!data || !data.ok) {
                alert(data?.error || 'Error');
                return null;
            }
            // если toggle снял товар — добавим снова
            if (!data.in_cart) return doToggle();
            return data;
        })
        .then(function (data) {
            if (!data || !data.ok) return;
            if (typeof updateCartBadges === 'function') updateCartBadges(data.count);
            const status = document.getElementById('live-sheet-status');
            if (status) {
                status.textContent = window.__i18n?.['live.added_cart'] || 'Добавлено в корзину';
                status.classList.remove('hidden');
            }
        })
        .catch(function () {});
}

function liveSheetBuyNow() {
    if (!liveSheetProduct) return;
    if (!window.__currentUserId) {
        rememberLiveStreamReturn();
        location.href = window.__loginUrl || '/login';
        return;
    }
    rememberLiveStreamReturn();
    const url = liveSheetProduct.buy_url || liveSheetProduct.url;
    if (!url) return;
    const w = window.open(url, '_blank');
    const status = document.getElementById('live-sheet-status');
    if (!w) {
        if (status) {
            status.textContent = window.__i18n?.['live.buy_new_tab'] || 'Разрешите всплывающие окна';
            status.classList.remove('hidden');
        }
        return;
    }
    if (status) {
        status.textContent = window.__i18n?.['live.buy_new_tab'] || 'Оплата в новой вкладке — эфир здесь';
        status.classList.remove('hidden');
    }
}

function applyLiveShopChatEnabled(enabled) {
    const form = document.getElementById('live-shop-comment-form');
    const input = document.getElementById('live-shop-comment-input');
    if (input) {
        if (!enabled) {
            input.disabled = true;
            input.placeholder = window.__i18n?.['home.live_setup_chat_off'] || 'Чат выключен';
        } else if (window.__currentUserId) {
            input.disabled = false;
            input.placeholder = window.__i18n?.['live.comment_placeholder'] || 'Напишите комментарий…';
        }
    }
    if (form) form.classList.toggle('opacity-60', !enabled);
}

function applyLiveShopGiveaway(giveaway) {
    const box = document.getElementById('live-shop-giveaway');
    if (!box) return;
    if (!giveaway || !giveaway.title) {
        box.classList.add('hidden');
        return;
    }
    box.classList.remove('hidden');
    liveShopGiveGoal = Math.max(50, Number(giveaway.goal) || 500);
    const titleEl = document.getElementById('live-shop-give-title');
    if (titleEl) titleEl.textContent = giveaway.title;
    const goalEl = document.getElementById('live-shop-give-goal');
    if (goalEl) goalEl.textContent = String(liveShopGiveGoal);
}

function updateLiveGiveawayProgress(likes, giveaway) {
    if (giveaway && giveaway.goal) {
        liveShopGiveGoal = Math.max(50, Number(giveaway.goal) || 500);
    }
    const goal = liveShopGiveGoal || 500;
    const n = Math.min(goal, Number(likes) || 0);
    const bar = document.getElementById('live-shop-give-bar');
    const prog = document.getElementById('live-shop-give-prog');
    const count = document.getElementById('live-shop-give-count');
    const goalEl = document.getElementById('live-shop-give-goal');
    if (bar) bar.style.width = Math.round((n / goal) * 100) + '%';
    if (prog) prog.textContent = String(n);
    if (goalEl) goalEl.textContent = String(goal);
    if (count && !liveShopGiveJoined) {
        liveShopGiveCount = Math.max(liveShopGiveCount || 0, Math.floor(n / 2));
        count.textContent = String(liveShopGiveCount);
    }
}

function joinLiveGiveaway() {
    if (!window.__currentUserId) {
        alert(window.__i18n?.['live.login_to_comment'] || 'Войдите');
        return;
    }
    if (!liveShopGiveJoined) {
        liveShopGiveJoined = true;
        liveShopGiveCount = (liveShopGiveCount || 0) + 1;
        const count = document.getElementById('live-shop-give-count');
        if (count) count.textContent = String(liveShopGiveCount);
    }
    sendLiveHeart();
    const btn = document.getElementById('live-shop-give-btn');
    if (btn) {
        btn.textContent = window.__i18n?.['live.giveaway_joined'] || 'Вы участвуете!';
        btn.classList.add('opacity-80');
    }
}

function liveShopAsk() {
    const input = document.getElementById('live-shop-comment-input');
    if (!input) return;
    if (!input.value.trim()) input.value = '? ';
    input.focus();
}

function liveShopShare() {
    const url = location.href.split('#')[0] + '#live-' + (liveShopStreamId || '');
    if (navigator.share) {
        navigator.share({ title: 'Live — zakopeyki.kz', url: url }).catch(function () {});
        return;
    }
    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(url).then(function () {
            alert(window.__i18n?.['live.share_copied'] || 'Ссылка скопирована');
        }).catch(function () {});
    }
}

function renderLiveShopShelf(products, featuredId) {
    const wrap = document.getElementById('live-shop-shelf-wrap');
    const shelf = document.getElementById('live-shop-shelf');
    const countEl = document.getElementById('live-shop-shelf-count');
    const badge = document.getElementById('live-shop-products-badge');
    const btn = document.getElementById('live-shop-products-btn');
    if (!wrap || !shelf) return;
    if (!products.length) {
        wrap.classList.add('hidden');
        wrap.classList.remove('is-open');
        shelf.innerHTML = '';
        if (badge) {
            badge.textContent = '0';
            badge.classList.add('hidden');
        }
        if (btn) btn.classList.add('opacity-50');
        return;
    }
    if (btn) btn.classList.remove('opacity-50');
    if (countEl) countEl.textContent = String(products.length);
    if (badge) {
        badge.textContent = String(products.length);
        badge.classList.remove('hidden');
    }
    // Keep shelf collapsed by default so the streamer stays visible
    if (!wrap.classList.contains('is-open')) {
        wrap.classList.add('hidden');
    }
    window.__liveShopProducts = products;
    shelf.innerHTML = products.map(function (p) {
        const feat = Number(p.id) === Number(featuredId) ? ' is-feat' : '';
        const img = p.image
            ? '<img src="' + liveShopEsc(p.image) + '" alt="">'
            : '<div class="ph">—</div>';
        const pin = liveShopIsHost
            ? '<button type="button" class="block w-full text-[8px] font-bold text-amber-300 py-0.5 bg-black/30 border-0 cursor-pointer" data-pin-id="' + p.id + '">' + liveShopEsc(window.__i18n?.['live.pin'] || 'В эфир') + '</button>'
            : '';
        return '<div class="live-shop-shelf-item' + feat + '" data-product-id="' + p.id + '">'
            + '<button type="button" class="live-shelf-open block w-full text-left border-0 bg-transparent p-0 cursor-pointer text-inherit" data-open-id="' + p.id + '">'
            + img + '<span class="pr">' + liveShopEsc(p.price_label || '') + '</span></button>'
            + pin
            + '</div>';
    }).join('');
    shelf.querySelectorAll('[data-open-id]').forEach(function (btnOpen) {
        btnOpen.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const pid = Number(btnOpen.getAttribute('data-open-id'));
            const prod = (window.__liveShopProducts || []).find(function (x) { return Number(x.id) === pid; });
            if (prod) openLiveProductSheet(prod);
        });
    });
    if (liveShopIsHost) {
        shelf.querySelectorAll('[data-pin-id]').forEach(function (pinBtn) {
            pinBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                pinLiveProduct(pinBtn.getAttribute('data-pin-id'));
            });
        });
    }
}

function toggleLiveShopShelf(forceOpen) {
    const wrap = document.getElementById('live-shop-shelf-wrap');
    const btn = document.getElementById('live-shop-products-btn');
    if (!wrap) return;
    const products = window.__liveShopProducts || [];
    if (!products.length) return;
    const open = forceOpen === true ? true : forceOpen === false ? false : !wrap.classList.contains('is-open');
    if (open) {
        wrap.classList.remove('hidden');
        wrap.classList.add('is-open');
        if (btn) btn.classList.add('is-active');
    } else {
        closeLiveShopShelf();
    }
}

function closeLiveShopShelf() {
    const wrap = document.getElementById('live-shop-shelf-wrap');
    const btn = document.getElementById('live-shop-products-btn');
    if (wrap) {
        wrap.classList.add('hidden');
        wrap.classList.remove('is-open');
    }
    if (btn) btn.classList.remove('is-active');
}

function pinLiveProduct(productId) {
    if (!liveShopIsHost || !liveShopStreamId || !window.__streamLiveFeature) return;
    const body = new FormData();
    body.append('stream_id', String(liveShopStreamId));
    body.append('product_id', String(productId));
    fetch(window.__streamLiveFeature, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.ok) pollLiveShop();
        })
        .catch(function () {});
}

function pollLiveComments() {
    if (!liveShopStreamId || !window.__streamLiveComments) return;
    const url = window.__streamLiveComments
        + '?stream_id=' + encodeURIComponent(liveShopStreamId)
        + '&after=' + encodeURIComponent(liveShopCommentAfter);
    fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.ok) return;
            (data.comments || []).forEach(appendLiveComment);
        })
        .catch(function () {});
}

function appendLiveComment(c) {
    if (!c || !c.id) return;
    if (c.id > liveShopCommentAfter) liveShopCommentAfter = c.id;
    const box = document.getElementById('live-shop-comments');
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'live-cmt' + (c.is_host ? ' is-host' : '');
    const hostTag = c.is_host ? '<span class="host-tag">ВЕДУЩИЙ</span>' : '';
    el.innerHTML = hostTag + '<strong>' + liveShopEsc(c.user_name) + '</strong>' + liveShopEsc(c.body);
    box.appendChild(el);
    while (box.children.length > 40) box.removeChild(box.firstChild);
    box.scrollTop = box.scrollHeight;
}

function sendLiveComment(e) {
    if (e && e.preventDefault) e.preventDefault();
    if (!window.__currentUserId) {
        alert(window.__i18n?.['live.login_to_comment'] || 'Войдите, чтобы комментировать');
        return false;
    }
    const input = document.getElementById('live-shop-comment-input');
    const body = (input?.value || '').trim();
    if (!body || !liveShopStreamId || !window.__streamLiveComment) return false;
    const fd = new FormData();
    fd.append('stream_id', String(liveShopStreamId));
    fd.append('body', body);
    input.value = '';
    fetch(window.__streamLiveComment, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.ok && data.comment) appendLiveComment(data.comment);
        })
        .catch(function () {});
    return false;
}

function sendLiveHeart() {
    if (!liveShopStreamId || !window.__streamLiveLike) return;
    spawnLiveHeart();
    const fd = new FormData();
    fd.append('stream_id', String(liveShopStreamId));
    fetch(window.__streamLiveLike, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.ok) {
                const l = document.getElementById('live-shop-likes');
                const ht = document.getElementById('live-shop-hearts-total');
                if (l) l.textContent = liveShopFormatLikes(data.likes);
                if (ht) ht.textContent = liveShopFormatLikes(data.likes);
                updateLiveGiveawayProgress(data.likes);
            }
        })
        .catch(function () {});
}

function spawnLiveHeart() {
    const box = document.getElementById('live-shop-hearts');
    if (!box) return;
    const colors = ['#ff4d6d', '#ff8fab', '#ffb703', '#80ed99', '#4cc9f0', '#f72585'];
    const el = document.createElement('span');
    el.className = 'h';
    el.textContent = '♥';
    el.style.color = colors[Math.floor(Math.random() * colors.length)];
    el.style.right = (2 + Math.random() * 18) + 'px';
    box.appendChild(el);
    setTimeout(function () { el.remove(); }, 1500);
}

function updateLiveShopCartBadge(count) {
    const badge = document.getElementById('live-shop-cart-badge');
    if (!badge) return;
    const n = Number(count) || 0;
    if (n > 0) {
        badge.textContent = String(n);
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
}

function updateMuteBtn() {
    const btn = document.getElementById('stream-mute-btn');
    const liveBtn = document.getElementById('live-shop-mute');
    const icon = streamMuted
        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>'
        : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>';
    if (btn) {
        btn.innerHTML = streamMuted
            ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>'
            : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>';
    }
    if (liveBtn) liveBtn.innerHTML = icon;
}

window.addEventListener('beforeunload', function () {
    if (window.__myLiveId) {
        const params = new URLSearchParams({ id: String(window.__myLiveId) });
        if (navigator.sendBeacon) {
            navigator.sendBeacon(window.__streamLiveEnd, params);
        }
        stopLiveRtc();
        stopLiveCamera();
    } else if (liveViewerPeerId && liveViewerStreamId && navigator.sendBeacon && window.__streamLiveSignal) {
        const params = new URLSearchParams({
            role: 'viewer',
            stream_id: String(liveViewerStreamId),
            peer_id: liveViewerPeerId,
            type: 'leave'
        });
        navigator.sendBeacon(window.__streamLiveSignal, params);
    }
});

function trackStreamProgress(video) {
    const bar = document.querySelector('[data-sbar="' + streamIndex + '"]');
    function tick() {
        if (!video.duration || isNaN(video.duration)) {
            streamProgressRaf = requestAnimationFrame(tick);
            return;
        }
        if (bar) {
            bar.style.width = Math.min(100, (video.currentTime / video.duration) * 100) + '%';
        }
        if (!video.paused && !video.ended) {
            streamProgressRaf = requestAnimationFrame(tick);
        }
    }
    streamProgressRaf = requestAnimationFrame(tick);
}

function animateFakeProgress(ms) {
    const bar = document.querySelector('[data-sbar="' + streamIndex + '"]');
    if (!bar) return;
    bar.style.transition = 'none';
    bar.style.width = '0%';
    requestAnimationFrame(function () {
        bar.style.transition = 'width ' + ms + 'ms linear';
        bar.style.width = '100%';
    });
}

function nextStream() {
    clearTimeout(window.__streamEmbedTimer);
    const streams = window.__streams || [];
    if (streamIndex < streams.length - 1) {
        streamIndex++;
        renderStreamReel();
    } else {
        closeStreamViewer();
    }
}

function prevStream() {
    clearTimeout(window.__streamEmbedTimer);
    const video = document.getElementById('stream-video');
    if (video && video.currentTime > 1.5) {
        video.currentTime = 0;
        video.play().catch(function () {});
        return trackStreamProgress(video);
    }
    if (streamIndex > 0) {
        streamIndex--;
        renderStreamReel();
    } else {
        renderStreamReel();
    }
}

function toggleStreamMute() {
    streamMuted = !streamMuted;
    const video = document.getElementById('stream-video');
    const cam = document.getElementById('stream-live-cam');
    const audioEl = document.getElementById('stream-live-audio');
    if (video) video.muted = streamMuted;
    if (!window.__myLiveId) {
        if (audioEl) {
            audioEl.muted = streamMuted;
            audioEl.volume = 1;
            if (!streamMuted) {
                if (audioEl.srcObject instanceof MediaStream) {
                    audioEl.srcObject.getAudioTracks().forEach(function (t) { t.enabled = true; });
                }
                audioEl.play().catch(function () {});
            }
        }
        if (cam) cam.muted = true; // картинка без локального эха; звук только с <audio>
        showLiveUnmuteBtn(streamMuted && !!(audioEl && audioEl.srcObject));
    }
    updateMuteBtn();
}

function pauseStreamHold() {
    const video = document.getElementById('stream-video');
    if (video && !video.paused) {
        video.pause();
        document.getElementById('stream-paused')?.classList.remove('hidden');
    }
}

function resumeStreamHold() {
    const video = document.getElementById('stream-video');
    document.getElementById('stream-paused')?.classList.add('hidden');
    if (video) {
        video.play().catch(function () {});
        trackStreamProgress(video);
    }
}

let streamGesturesBound = false;
function bindStreamGestures() {
    if (streamGesturesBound) return;
    streamGesturesBound = true;

    document.getElementById('stream-tap-prev')?.addEventListener('click', function (e) {
        e.stopPropagation();
        prevStream();
    });
    document.getElementById('stream-tap-next')?.addEventListener('click', function (e) {
        e.stopPropagation();
        nextStream();
    });

    document.getElementById('stream-live-cam')?.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!window.__myLiveId && streamMuted) unmuteLiveStream();
    });

    const hold = document.getElementById('stream-hold-zone');
    if (!hold) return;

    const startHold = function (e) {
        if (e.target.closest('#stream-tap-prev, #stream-tap-next, button, form, a, #stream-live-unmute')) return;
        streamHoldTimer = setTimeout(pauseStreamHold, 120);
    };
    const endHold = function () {
        clearTimeout(streamHoldTimer);
        resumeStreamHold();
    };

    hold.addEventListener('pointerdown', startHold);
    hold.addEventListener('pointerup', endHold);
    hold.addEventListener('pointerleave', endHold);
    hold.addEventListener('pointercancel', endHold);
}

/* ===== Favorites ===== */
function setFavoriteButtonState(btn, favorited) {
    const on = !!favorited;
    btn.dataset.favorited = on ? '1' : '0';
    btn.classList.toggle('is-favorited', on);
    btn.classList.toggle('text-red-500', on);
    btn.classList.toggle('text-gray-400', !on);
    btn.setAttribute('aria-label', on ? (window.__i18n?.['card.unfavorite'] || 'Убрать из избранного') : (window.__i18n?.['card.favorite'] || 'В избранное'));
    const svg = btn.querySelector('svg');
    if (svg) svg.setAttribute('fill', on ? 'currentColor' : 'none');
}

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.favorite-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    if (!window.__isLoggedIn) {
        window.location.href = window.__loginUrl || '/login';
        return;
    }

    const productId = btn.dataset.productId;
    if (!productId || btn.dataset.busy === '1') return;

    const base = window.__favoritesToggleBase || '/favorites/';
    btn.dataset.busy = '1';
    btn.classList.add('opacity-60');

    fetch(base + productId + '/toggle', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    })
        .then(function (r) {
            if (r.status === 401) {
                window.location.href = window.__loginUrl || '/login';
                return null;
            }
            return r.json();
        })
        .then(function (data) {
            if (!data) return;
            if (data.ok) {
                setFavoriteButtonState(btn, data.favorited);
                const grid = btn.closest('[data-favorites-grid]');
                if (!data.favorited && grid) {
                    btn.closest('article')?.remove();
                    if (!grid.querySelector('article')) {
                        window.location.reload();
                    }
                }
            }
        })
        .catch(function () { /* ignore */ })
        .finally(function () {
            btn.dataset.busy = '0';
            btn.classList.remove('opacity-60');
        });
});

function setFollowButtonState(btn, on) {
    if (!btn) return;
    btn.dataset.following = on ? '1' : '0';
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    const label = on
        ? (window.__i18n?.['seller.unsubscribe'] || window.__i18n?.['live.unsubscribe'] || 'Отписаться')
        : (window.__i18n?.['seller.subscribe'] || window.__i18n?.['live.subscribe'] || 'Подписаться');
    btn.textContent = label;
    if (btn.classList.contains('follow-btn')) {
        btn.classList.toggle('bg-brand-500', !on);
        btn.classList.toggle('hover:bg-brand-600', !on);
        btn.classList.toggle('text-white', !on);
        btn.classList.toggle('shadow-sm', !on);
        btn.classList.toggle('bg-ink-100', on);
        btn.classList.toggle('dark:bg-white/10', on);
        btn.classList.toggle('text-ink-800', on);
        btn.classList.toggle('dark:text-white', on);
        btn.classList.toggle('hover:bg-ink-200', on);
        btn.classList.toggle('dark:hover:bg-white/15', on);
    }
}

/* ===== Seller profile modal ===== */
let sellerProfileRequestId = 0;
let sellerProfileData = null;
let sellerProductsVisible = 6;

function closeSellerProfile() {
    const modal = document.getElementById('seller-profile-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    document.body.style.overflow = '';
    sellerProfileData = null;
    closeSellerProfileMenu();
    try {
        const url = new URL(window.location.href);
        if (url.searchParams.has('seller')) {
            url.searchParams.delete('seller');
            window.history.replaceState({}, '', url.pathname + url.search + url.hash);
        }
    } catch (e) { /* ignore */ }
}

function closeSellerProfileMenu() {
    const dd = document.getElementById('seller-profile-menu-dd');
    const btn = document.getElementById('seller-profile-menu');
    if (dd) dd.classList.add('hidden');
    if (btn) btn.setAttribute('aria-expanded', 'false');
}

function toggleSellerProfileMenu() {
    const dd = document.getElementById('seller-profile-menu-dd');
    const btn = document.getElementById('seller-profile-menu');
    if (!dd) return;
    const open = dd.classList.contains('hidden');
    dd.classList.toggle('hidden', !open);
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function copySellerProfileLink() {
    const id = sellerProfileData && sellerProfileData.id;
    if (!id) return;
    const base = window.__usersBase || '/users/';
    const link = window.location.origin + String(base).replace(/\/?$/, '/') + id;
    const done = function () {
        alert(window.__i18n?.['seller.link_copied'] || 'Ссылка скопирована');
        closeSellerProfileMenu();
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(done).catch(function () {
            window.prompt(window.__i18n?.['seller.copy_link'] || 'Скопировать ссылку', link);
            closeSellerProfileMenu();
        });
    } else {
        window.prompt(window.__i18n?.['seller.copy_link'] || 'Скопировать ссылку', link);
        closeSellerProfileMenu();
    }
}

function openSellerProfile(userId) {
    const id = Number(userId) || 0;
    if (id <= 0) return;
    const modal = document.getElementById('seller-profile-modal');
    if (!modal) return;

    const loading = document.getElementById('seller-profile-loading');
    const errorEl = document.getElementById('seller-profile-error');
    const body = document.getElementById('seller-profile-body');
    if (loading) loading.classList.remove('hidden');
    if (errorEl) {
        errorEl.classList.add('hidden');
        errorEl.textContent = '';
    }
    if (body) body.classList.add('hidden');

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    setSellerShopTab('products');

    const req = ++sellerProfileRequestId;
    const base = window.__usersBase || '/users/';
    fetch(base + id, {
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
        .then(function (r) {
            if (!r.ok) throw new Error('fail');
            return r.json();
        })
        .then(function (data) {
            if (req !== sellerProfileRequestId) return;
            if (!data || !data.ok) throw new Error('fail');
            sellerProductsVisible = 6;
            renderSellerProfile(data);
        })
        .catch(function () {
            if (req !== sellerProfileRequestId) return;
            if (loading) loading.classList.add('hidden');
            if (errorEl) {
                errorEl.textContent = window.__i18n?.['seller.not_found'] || 'Продавец не найден';
                errorEl.classList.remove('hidden');
            }
        });
}

function sellerFmtCount(n) {
    n = Number(n) || 0;
    if (n >= 1000) {
        const v = n / 1000;
        return (v >= 10 ? Math.round(v) : v.toFixed(1).replace(/\.0$/, '')) + 'K';
    }
    return String(n);
}

function sellerParsePrice(label) {
    const digits = String(label || '').replace(/[^\d]/g, '');
    return digits ? parseInt(digits, 10) : 0;
}

function sellerStars(rating) {
    const n = Math.max(0, Math.min(5, Math.round(Number(rating) || 0)));
    return '★'.repeat(n) + '☆'.repeat(5 - n);
}

function sellerEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function setSellerShopTab(tab) {
    document.querySelectorAll('.seller-shop-tab').forEach(function (btn) {
        btn.classList.toggle('is-active', btn.dataset.sellerTab === tab);
    });
    document.querySelectorAll('[data-seller-pane]').forEach(function (pane) {
        pane.classList.toggle('hidden', pane.dataset.sellerPane !== tab);
    });
}

function renderSellerProfile(data) {
    sellerProfileData = data;
    const loading = document.getElementById('seller-profile-loading');
    const body = document.getElementById('seller-profile-body');
    if (loading) loading.classList.add('hidden');
    if (body) body.classList.remove('hidden');

    const avatar = document.getElementById('seller-profile-avatar');
    if (avatar) {
        avatar.innerHTML = '';
        if (data.avatar_url) {
            const img = document.createElement('img');
            img.src = data.avatar_url;
            img.alt = '';
            img.className = 'w-full h-full object-cover';
            avatar.appendChild(img);
            avatar.classList.remove('font-black', 'text-white', 'text-2xl');
        } else {
            avatar.textContent = data.avatar_initial || '?';
            avatar.classList.add('font-black', 'text-white', 'text-2xl');
        }
    }

    const nameEl = document.getElementById('seller-profile-name');
    if (nameEl) nameEl.textContent = data.name || '';

    const sinceEl = document.getElementById('seller-profile-since');
    if (sinceEl) sinceEl.textContent = data.member_since || '';

    const onlineEl = document.getElementById('seller-profile-online');
    if (onlineEl) onlineEl.classList.toggle('hidden', !data.is_online);

    const reviewsCountEl = document.getElementById('seller-tab-reviews-count');
    if (reviewsCountEl) {
        const rc = Number(data.rating_count || 0);
        reviewsCountEl.textContent = rc > 0 ? ' ' + rc : '';
    }

    const stats = document.getElementById('seller-profile-stats');
    if (stats) {
        const ratingCount = Number(data.rating_count || 0);
        const ratingText = ratingCount > 0 ? Number(data.rating_avg || 0).toFixed(1) : '—';
        const ratingSub = ratingCount > 0
            ? (window.__i18n?.['seller.stat_rating_sub'] || 'на основе :n отзывов').replace(':n', String(ratingCount))
            : (window.__i18n?.['seller.stat_rating_empty'] || 'пока нет отзывов');
        const responseRaw = data.response_time;
        const responseText = responseRaw ? String(responseRaw) : '—';
        const responseSub = responseRaw
            ? (window.__i18n?.['seller.stat_response_sub'] || 'в среднем')
            : (window.__i18n?.['seller.stat_response_empty'] || 'нет данных');

        const iconStar = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.9 6.9L22 10l-5 4.6L18.2 22 12 18.2 5.8 22 7 14.6 2 10l7.1-1.1L12 2z"/></svg>';
        const iconBag = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 7h12l-1 13H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>';
        const iconUsers = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
        const iconUser = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
        const iconClock = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';

        stats.innerHTML = [
            {
                label: window.__i18n?.['seller.stat_rating'] || 'Рейтинг продавца',
                value: ratingText,
                sub: ratingSub,
                icon: iconStar,
                iconClass: 'is-star'
            },
            {
                label: window.__i18n?.['seller.stat_sales'] || 'Продаж',
                value: sellerFmtCount(data.sales_count),
                sub: window.__i18n?.['seller.stat_sales_sub'] || 'успешных',
                icon: iconBag
            },
            {
                label: window.__i18n?.['seller.stat_followers'] || 'Подписчики',
                value: '<span id="seller-profile-followers">' + sellerFmtCount(data.followers_count) + '</span>',
                sub: window.__i18n?.['seller.stat_people'] || 'человек',
                icon: iconUsers
            },
            {
                label: window.__i18n?.['seller.stat_following'] || 'Подписки',
                value: sellerFmtCount(data.following_count),
                sub: window.__i18n?.['seller.stat_people'] || 'человек',
                icon: iconUser
            },
            {
                label: window.__i18n?.['seller.stat_response'] || 'Время ответа',
                value: responseText,
                sub: responseSub,
                icon: iconClock
            }
        ].map(function (s) {
            return '<div class="seller-shop-stat">'
                + '<span class="seller-shop-stat-label">' + sellerEsc(s.label) + '</span>'
                + '<span class="seller-shop-stat-value">'
                + '<span class="seller-shop-stat-icon' + (s.iconClass ? ' ' + s.iconClass : '') + '">' + s.icon + '</span>'
                + s.value
                + '</span>'
                + '<span class="seller-shop-stat-sub">' + sellerEsc(s.sub) + '</span>'
                + '</div>';
        }).join('');
    }

    const actions = document.getElementById('seller-profile-actions');
    if (actions) {
        actions.innerHTML = '';
        if (data.is_own) {
            const a = document.createElement('a');
            a.href = data.profile_url || '#';
            a.className = 'inline-flex items-center justify-center h-10 px-4 rounded-xl bg-ink-100 dark:bg-white/10 text-ink-800 dark:text-white font-display font-bold text-xs uppercase tracking-wider transition hover:bg-ink-200 dark:hover:bg-white/15';
            a.textContent = window.__i18n?.['seller.edit_profile'] || 'Мой профиль';
            actions.appendChild(a);
        } else {
            const follow = document.createElement('button');
            follow.type = 'button';
            follow.className = 'follow-btn inline-flex items-center justify-center gap-1.5 h-10 px-4 rounded-xl font-display font-bold text-xs uppercase tracking-wider transition';
            follow.setAttribute('data-user-id', String(data.id));
            follow.dataset.userId = String(data.id);
            setFollowButtonState(follow, !!data.is_following);
            actions.appendChild(follow);

            const msg = document.createElement('a');
            msg.href = window.__isLoggedIn
                ? (data.chat_url || ((window.__chatStartUrl || '/chat/start') + '?user_id=' + data.id))
                : (window.__loginUrl || '/login');
            msg.className = 'inline-flex items-center justify-center gap-1.5 h-10 px-4 rounded-xl border border-black/[0.1] dark:border-white/15 bg-white dark:bg-white/5 text-ink-800 dark:text-white font-display font-bold text-xs uppercase tracking-wider transition hover:border-[#7c3aed]/40';
            msg.innerHTML = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>'
                + '<span>' + sellerEsc(window.__i18n?.['seller.write'] || 'Написать') + '</span>';
            actions.appendChild(msg);
        }
    }

    const bio = document.getElementById('seller-profile-bio');
    const bioEmpty = document.getElementById('seller-profile-bio-empty');
    if (bio && bioEmpty) {
        if (data.bio) {
            bio.textContent = data.bio;
            bio.classList.remove('hidden');
            bioEmpty.classList.add('hidden');
        } else {
            bio.textContent = '';
            bio.classList.add('hidden');
            bioEmpty.classList.remove('hidden');
        }
    }

    const search = document.getElementById('seller-products-search');
    const sort = document.getElementById('seller-products-sort');
    if (search) search.value = '';
    if (sort) sort.value = 'new';

    renderSellerProducts();
    renderSellerReviews();
}

function getSellerFilteredProducts() {
    if (!sellerProfileData) return [];
    let list = Array.isArray(sellerProfileData.products) ? sellerProfileData.products.slice() : [];
    const q = (document.getElementById('seller-products-search')?.value || '').trim().toLowerCase();
    if (q) {
        list = list.filter(function (p) {
            return String(p.title || '').toLowerCase().indexOf(q) !== -1;
        });
    }
    const sort = document.getElementById('seller-products-sort')?.value || 'new';
    if (sort === 'price_asc') {
        list.sort(function (a, b) { return sellerParsePrice(a.price_label) - sellerParsePrice(b.price_label); });
    } else if (sort === 'price_desc') {
        list.sort(function (a, b) { return sellerParsePrice(b.price_label) - sellerParsePrice(a.price_label); });
    }
    return list;
}

function renderSellerProducts() {
    const lots = document.getElementById('seller-profile-lots');
    const empty = document.getElementById('seller-products-empty');
    const moreBtn = document.getElementById('seller-products-more');
    if (!lots) return;

    const list = getSellerFilteredProducts();
    lots.innerHTML = '';
    if (!list.length) {
        if (empty) empty.classList.remove('hidden');
        if (moreBtn) moreBtn.classList.add('hidden');
        return;
    }
    if (empty) empty.classList.add('hidden');

    const visible = list.slice(0, sellerProductsVisible);
    visible.forEach(function (p) {
        const card = document.createElement('article');
        card.className = 'seller-shop-card';

        const imgWrap = document.createElement('div');
        imgWrap.className = 'seller-shop-card-img';
        const imgLink = document.createElement('a');
        imgLink.href = p.url || '#';
        imgLink.addEventListener('click', function () { closeSellerProfile(); });
        if (p.image) {
            const img = document.createElement('img');
            img.src = p.image;
            img.alt = '';
            imgLink.appendChild(img);
        } else {
            imgLink.className = 'w-full h-full flex items-center justify-center text-gray-300 text-2xl';
            imgLink.textContent = '·';
        }
        imgWrap.appendChild(imgLink);

        const fav = document.createElement('button');
        fav.type = 'button';
        fav.className = 'favorite-btn absolute top-2 right-2 z-10 w-8 h-8 rounded-xl bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center transition hover:scale-105 '
            + (p.favorited ? 'is-favorited text-red-500' : 'text-gray-400 hover:text-red-500');
        fav.dataset.productId = String(p.id);
        fav.dataset.favorited = p.favorited ? '1' : '0';
        fav.innerHTML = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="' + (p.favorited ? 'currentColor' : 'none') + '" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>';
        imgWrap.appendChild(fav);

        const bodyBox = document.createElement('div');
        bodyBox.className = 'seller-shop-card-body';
        const title = document.createElement('a');
        title.href = p.url || '#';
        title.className = 'text-sm font-semibold text-ink-800 dark:text-gray-100 line-clamp-2 min-h-[2.5rem]';
        title.textContent = p.title || '';
        title.addEventListener('click', function () { closeSellerProfile(); });
        const price = document.createElement('div');
        price.className = 'text-sm font-display font-extrabold text-ink-900 dark:text-white';
        price.textContent = p.price_label || '';
        bodyBox.appendChild(title);
        bodyBox.appendChild(price);

        if (p.can_cart) {
            const cart = document.createElement('button');
            cart.type = 'button';
            cart.className = 'cart-btn mt-auto w-full h-9 rounded-xl bg-[#7c3aed] hover:bg-[#6d28d9] text-white font-display font-bold text-[10px] uppercase tracking-wider transition';
            cart.dataset.productId = String(p.id);
            cart.dataset.inCart = '0';
            cart.textContent = window.__i18n?.['card.add_cart'] || 'В корзину';
            bodyBox.appendChild(cart);
        }

        card.appendChild(imgWrap);
        card.appendChild(bodyBox);
        lots.appendChild(card);
    });

    if (moreBtn) {
        moreBtn.classList.toggle('hidden', list.length <= sellerProductsVisible);
    }
}

function renderSellerReviewCard(r) {
    const wrap = document.createElement('article');
    wrap.className = 'seller-shop-review';

    const main = document.createElement('div');
    main.className = 'min-w-0 space-y-2';

    const head = document.createElement('div');
    head.className = 'flex items-center gap-2.5';
    const av = document.createElement('div');
    av.className = 'w-9 h-9 rounded-full overflow-hidden bg-[#7c3aed] text-white text-xs font-black flex items-center justify-center shrink-0';
    if (r.author_avatar_url) {
        const img = document.createElement('img');
        img.src = r.author_avatar_url;
        img.alt = '';
        img.className = 'w-full h-full object-cover';
        av.appendChild(img);
    } else {
        av.textContent = r.author_initial || '?';
    }
    const meta = document.createElement('div');
    meta.className = 'min-w-0';
    meta.innerHTML = '<div class="text-sm font-semibold text-ink-900 dark:text-white truncate">' + sellerEsc(r.author_name || '') + '</div>'
        + '<div class="text-[11px] text-gray-400">' + sellerEsc((r.created_at || '').slice(0, 10)) + '</div>';
    head.appendChild(av);
    head.appendChild(meta);

    const stars = document.createElement('div');
    stars.className = 'seller-shop-stars';
    stars.textContent = sellerStars(r.rating);

    const comment = document.createElement('p');
    comment.className = 'text-sm text-ink-700 dark:text-gray-300 leading-relaxed';
    comment.textContent = r.comment || '';

    main.appendChild(head);
    main.appendChild(stars);
    if (r.comment) main.appendChild(comment);

    if (r.product_id || r.product_title) {
        const productRow = document.createElement(r.product_url ? 'a' : 'div');
        if (r.product_url) {
            productRow.href = r.product_url;
            productRow.addEventListener('click', function () { closeSellerProfile(); });
        }
        productRow.className = 'seller-shop-review-product';
        if (r.product_image) {
            const thumb = document.createElement('div');
            thumb.className = 'seller-shop-review-product-thumb';
            thumb.innerHTML = '<img src="' + sellerEsc(r.product_image) + '" alt="">';
            productRow.appendChild(thumb);
        } else {
            const thumb = document.createElement('div');
            thumb.className = 'seller-shop-review-product-thumb is-empty';
            thumb.textContent = '·';
            productRow.appendChild(thumb);
        }
        const info = document.createElement('div');
        info.className = 'min-w-0 flex-1';
        const label = document.createElement('div');
        label.className = 'text-[10px] font-semibold uppercase tracking-wider text-gray-400';
        label.textContent = window.__i18n?.['seller.review_about_product'] || 'Товар';
        const title = document.createElement('div');
        title.className = 'text-xs font-semibold text-ink-800 dark:text-gray-100 line-clamp-2';
        title.textContent = r.product_title || (window.__i18n?.['seller.review_product_fallback'] || 'Товар из сделки');
        info.appendChild(label);
        info.appendChild(title);
        if (r.product_price_label) {
            const price = document.createElement('div');
            price.className = 'text-xs font-bold text-[#7c3aed] mt-0.5';
            price.textContent = r.product_price_label;
            info.appendChild(price);
        }
        productRow.appendChild(info);
        main.appendChild(productRow);
    }

    wrap.appendChild(main);
    return wrap;
}

function renderSellerReviews() {
    const previewWrap = document.getElementById('seller-reviews-preview');
    const previewList = document.getElementById('seller-reviews-preview-list');
    const full = document.getElementById('seller-reviews-full');
    const empty = document.getElementById('seller-reviews-empty');
    const reviews = Array.isArray(sellerProfileData?.reviews) ? sellerProfileData.reviews : [];

    if (previewList) previewList.innerHTML = '';
    if (full) full.innerHTML = '';

    if (!reviews.length) {
        if (previewWrap) previewWrap.classList.add('hidden');
        if (empty) empty.classList.remove('hidden');
        return;
    }
    if (empty) empty.classList.add('hidden');
    if (previewWrap) previewWrap.classList.remove('hidden');

    reviews.slice(0, 2).forEach(function (r) {
        previewList?.appendChild(renderSellerReviewCard(r));
    });
    reviews.forEach(function (r) {
        full?.appendChild(renderSellerReviewCard(r));
    });
}

document.addEventListener('click', function (e) {
    const modal = document.getElementById('seller-profile-modal');
    const modalOpen = modal && !modal.classList.contains('hidden');

    if (modalOpen && e.target === modal) {
        closeSellerProfile();
        return;
    }
    if (e.target.closest('#seller-profile-back')) {
        closeSellerProfile();
        return;
    }
    if (e.target.closest('#seller-profile-menu')) {
        e.preventDefault();
        toggleSellerProfileMenu();
        return;
    }
    if (e.target.closest('#seller-profile-copy-link')) {
        e.preventDefault();
        copySellerProfileLink();
        return;
    }
    if (modalOpen && !e.target.closest('#seller-profile-menu-dd') && !e.target.closest('#seller-profile-menu')) {
        closeSellerProfileMenu();
    }

    const trigger = e.target.closest('.seller-profile-trigger');
    if (trigger) {
        e.preventDefault();
        openSellerProfile(trigger.dataset.sellerId || trigger.getAttribute('data-seller-id'));
        return;
    }
    const tab = e.target.closest('.seller-shop-tab');
    if (tab) {
        e.preventDefault();
        setSellerShopTab(tab.dataset.sellerTab || 'products');
        return;
    }
    if (e.target.closest('[data-seller-goto-reviews]')) {
        e.preventDefault();
        setSellerShopTab('reviews');
        return;
    }
    if (e.target.closest('#seller-products-more')) {
        e.preventDefault();
        sellerProductsVisible += 6;
        renderSellerProducts();
    }
});

document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'seller-products-search') {
        sellerProductsVisible = 6;
        renderSellerProducts();
    }
});

document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'seller-products-sort') {
        sellerProductsVisible = 6;
        renderSellerProducts();
    }
});

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    const modal = document.getElementById('seller-profile-modal');
    if (modal && !modal.classList.contains('hidden')) closeSellerProfile();
});

document.addEventListener('DOMContentLoaded', function () {
    try {
        const seller = new URLSearchParams(window.location.search).get('seller');
        if (seller) openSellerProfile(seller);
    } catch (err) { /* ignore */ }
});
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.follow-btn, #live-shop-follow');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    if (!window.__isLoggedIn) {
        window.location.href = window.__loginUrl || '/login';
        return;
    }

    const userId = btn.getAttribute('data-user-id') || btn.dataset.userId || '';
    if (!userId || userId === '0' || btn.dataset.busy === '1') return;

    const base = window.__usersFollowBase
        || (window.__usersBase)
        || ((window.__homeUrl || '/').replace(/\/?$/, '/') + 'users/');
    btn.dataset.busy = '1';
    btn.classList.add('opacity-60');

    const body = new FormData();
    const token = (typeof csrfToken === 'function' ? csrfToken() : '') || window.__csrfToken || '';
    if (token) body.append('_csrf', token);

    fetch(String(base).replace(/\/?$/, '/') + userId + '/follow', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        body: body,
        credentials: 'same-origin'
    })
        .then(function (r) {
            if (r.status === 401) {
                window.location.href = window.__loginUrl || '/login';
                return null;
            }
            return r.json().then(function (data) {
                return { status: r.status, data: data };
            }).catch(function () {
                return { status: r.status, data: null };
            });
        })
        .then(function (res) {
            if (!res) return;
            const data = res.data;
            if (data && data.ok) {
                setFollowButtonState(btn, data.following);
                const followers = document.getElementById('live-shop-followers');
                if (followers && data.followers_count != null && btn.id === 'live-shop-follow') {
                    followers.textContent = Number(data.followers_count) + ' ' + (window.__i18n?.['live.followers'] || 'подписчиков');
                }
                const modalFollowers = document.getElementById('seller-profile-followers');
                if (modalFollowers && data.followers_count != null) {
                    modalFollowers.textContent = typeof sellerFmtCount === 'function'
                        ? sellerFmtCount(data.followers_count)
                        : String(Number(data.followers_count) || 0);
                }
                document.querySelectorAll('.follow-btn[data-user-id="' + userId + '"], #live-shop-follow').forEach(function (other) {
                    if (other !== btn) setFollowButtonState(other, data.following);
                });
                const subList = btn.closest('[data-subscriptions-list]');
                if (subList && subList.dataset.subSection === 'following' && !data.following) {
                    btn.closest('[data-user-row]')?.remove();
                    if (!subList.querySelector('[data-user-row]')) {
                        window.location.reload();
                    }
                }
                return;
            }
            const err = (data && data.error) ? String(data.error) : '';
            if (err === 'login' || res.status === 401) {
                window.location.href = window.__loginUrl || '/login';
                return;
            }
            if (err === 'self') {
                alert(window.__i18n?.['seller.follow_self'] || 'Нельзя подписаться на себя');
                return;
            }
            alert(window.__i18n?.['seller.follow_error'] || 'Не удалось изменить подписку');
        })
        .catch(function () {
            alert(window.__i18n?.['seller.follow_error'] || 'Не удалось изменить подписку');
        })
        .finally(function () {
            btn.dataset.busy = '0';
            btn.classList.remove('opacity-60');
        });
});

/* ===== Cart ===== */
function updateCartBadges(count) {
    const n = Math.max(0, parseInt(count, 10) || 0);
    window.__cartCount = n;
    const label = n > 99 ? '99+' : String(n);

    const headerBadge = document.getElementById('header-cart-badge');
    if (headerBadge) {
        headerBadge.textContent = label;
        headerBadge.classList.toggle('hidden', n <= 0);
    }

    const sideBadge = document.getElementById('sidebar-cart-badge');
    if (sideBadge) {
        sideBadge.textContent = label;
        sideBadge.classList.toggle('hidden', n <= 0);
    }
    updateLiveShopCartBadge(n);
}

function setCartButtonState(btn, inCart) {
    const on = !!inCart;
    btn.dataset.inCart = on ? '1' : '0';
    btn.classList.toggle('is-in-cart', on);
    btn.classList.toggle('bg-brand-50/80', on);
    btn.classList.toggle('dark:bg-brand-500/10', on);
    btn.classList.toggle('text-brand-700', on);
    btn.classList.toggle('dark:text-brand-400', on);
    const label = on
        ? (window.__i18n?.['card.in_cart'] || 'В корзине')
        : (window.__i18n?.['card.add_cart'] || 'В корзину');
    btn.setAttribute('aria-label', label);
    btn.textContent = label;
}

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.cart-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    const productId = btn.dataset.productId;
    if (!productId || btn.dataset.busy === '1') return;

    const base = window.__cartToggleBase || '/cart/';
    btn.dataset.busy = '1';
    btn.classList.add('opacity-60');

    fetch(base + productId + '/toggle', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data) return;
            if (data.ok) {
                setCartButtonState(btn, data.in_cart);
                updateCartBadges(data.count);
            } else if (data.error) {
                alert(data.error);
            }
        })
        .catch(function () { /* ignore */ })
        .finally(function () {
            btn.dataset.busy = '0';
            btn.classList.remove('opacity-60');
        });
});

/* ===== AI Assistant (Support + Catalog + Self-learning) ===== */
let aiAssistantReady = false;
let aiChatBusy = false;
let aiConversationId = localStorage.getItem('zk_ai_conv_id') || null;
let aiGuestToken = localStorage.getItem('zk_ai_guest_token') || null;
let aiLastMessageId = 0;
let aiPollTimer = null;
let aiConversationStatus = 'ai_active';

function tJs(key, fallback) {
    return (window.__i18n && window.__i18n[key]) || fallback;
}

function aiCsrfHeaders() {
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    };
    if (window.__csrfToken) {
        headers['X-CSRF-TOKEN'] = window.__csrfToken;
    }
    if (aiGuestToken) {
        headers['X-Guest-Token'] = aiGuestToken;
    }
    return headers;
}

function toggleAiAssistant(force) {
    const panel = document.getElementById('ai-assistant-panel');
    const toggle = document.getElementById('ai-assistant-toggle');
    if (!panel) return;

    const currentlyOpen = !panel.classList.contains('hidden');
    const open = typeof force === 'boolean' ? force : !currentlyOpen;

    panel.classList.toggle('hidden', !open);
    panel.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (toggle) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (open) {
        initAiAssistant();
        startAiPolling();
        setTimeout(function () {
            document.getElementById('ai-chat-input')?.focus();
        }, 40);
    } else {
        stopAiPolling();
    }
}

window.toggleAiAssistant = toggleAiAssistant;

function initAiAssistant() {
    if (aiAssistantReady) return;
    aiAssistantReady = true;

    const form = document.getElementById('ai-chat-form');
    form?.addEventListener('submit', function (e) {
        e.preventDefault();
        const input = document.getElementById('ai-chat-input');
        const text = (input?.value || '').trim();
        if (!text) return;
        if (input) input.value = '';
        sendAiMessage(text);
    });

    if (aiConversationId) {
        loadAiHistory();
    } else {
        appendAiBot(
            tJs('ai.welcome', 'Привет! Я помощник Zakopeyki. Ищу товары и услуги в каталоге, отвечаю по безопасной сделке и доставке. Что нужно?'),
            [],
            [
                { label: tJs('ai.suggest_free', 'Бесплатно'), message: tJs('ai.msg_free', 'что отдают бесплатно') },
                { label: tJs('ai.suggest_exchange', 'Обмен'), message: tJs('ai.msg_exchange', 'ищу обмен') },
                { label: tJs('ai.suggest_services', 'Услуги'), message: tJs('ai.msg_services', 'ищу услуги') },
                { label: tJs('ai.suggest_sell', 'Как продать?'), message: tJs('ai.msg_sell', 'как разместить объявление') },
                { label: tJs('ai.suggest_auctions', 'Аукционы'), message: tJs('ai.msg_auctions', 'аукционы') }
            ]
        );
    }
}

function sendAiSuggestion(message) {
    sendAiMessage(message);
}

function sendAiMessage(text) {
    if (aiChatBusy || !text) return;
    aiChatBusy = true;

    appendAiUser(text);
    renderAiSuggestions([]);
    const typingId = appendAiTyping();

    const body = {
        message: text,
        guest_token: aiGuestToken
    };
    if (window.__isLoggedIn) {
        body.user_id = true;
    }

    fetch(window.__aiChatUrl || '/ai/chat', {
        method: 'POST',
        headers: aiCsrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify(body)
    })
        .then(function (r) {
            return r.json().catch(function () {
                return { ok: false, reply: tJs('ai.error_reply', 'Не удалось получить ответ.') };
            });
        })
        .then(function (data) {
            removeAiTyping(typingId);

            if (data && data.guest_token) {
                aiGuestToken = data.guest_token;
                localStorage.setItem('zk_ai_guest_token', aiGuestToken);
            }
            if (data && data.conversation_id) {
                aiConversationId = String(data.conversation_id);
                localStorage.setItem('zk_ai_conv_id', aiConversationId);
            }
            if (data && data.message_id) {
                aiLastMessageId = Math.max(aiLastMessageId, parseInt(data.message_id, 10) || 0);
            }
            if (data && data.ai_message_id) {
                aiLastMessageId = Math.max(aiLastMessageId, parseInt(data.ai_message_id, 10) || 0);
            }
            if (data && data.conversation_status) {
                updateAiHeaderStatus(data.conversation_status);
            }

            if (!data || data.ok === false) {
                appendAiBot(data?.reply || tJs('ai.error_reply', 'Не удалось получить ответ. Попробуйте ещё раз.'), [], []);
                return;
            }

            if (data.pending) {
                appendAiBot(tJs('ai.pending', 'AI готовит ответ…'), [], []);
                startAiPolling();
                return;
            }

            appendAiBot(
                data.reply || '',
                data.products || [],
                data.suggestions || [],
                data.ai_message_id || null
            );

            if (data.conversation_status === 'human_escalated') {
                startAiPolling();
            }
        })
        .catch(function () {
            removeAiTyping(typingId);
            appendAiBot(tJs('ai.error_network', 'Сеть недоступна. Проверьте соединение и повторите.'), [], []);
        })
        .finally(function () {
            aiChatBusy = false;
        });
}

function loadAiHistory() {
    if (!aiConversationId) return;
    const url = (window.__aiMessagesUrl || '/ai/chat/messages')
        + '?conversation_id=' + encodeURIComponent(aiConversationId)
        + (aiGuestToken ? '&guest_token=' + encodeURIComponent(aiGuestToken) : '');

    fetch(url, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.ok) return;
            const box = aiMessagesEl();
            if (box) box.innerHTML = '';
            if (data.conversation && data.conversation.status) {
                updateAiHeaderStatus(data.conversation.status);
            }
            (data.messages || []).forEach(function (msg) {
                aiLastMessageId = Math.max(aiLastMessageId, msg.id || 0);
                if (msg.sender_type === 'user') {
                    appendAiUser(msg.message);
                } else {
                    const products = (msg.meta && msg.meta.products) ? msg.meta.products : [];
                    appendAiBot(msg.message, products, [], msg.sender_type === 'ai' ? msg.id : null);
                }
            });
        })
        .catch(function () { /* ignore */ });
}

function pollAiMessages() {
    if (!aiConversationId) return;
    const url = (window.__aiMessagesUrl || '/ai/chat/messages')
        + '?conversation_id=' + encodeURIComponent(aiConversationId)
        + '&after_id=' + encodeURIComponent(String(aiLastMessageId))
        + (aiGuestToken ? '&guest_token=' + encodeURIComponent(aiGuestToken) : '');

    fetch(url, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.ok) return;
            if (data.conversation && data.conversation.status) {
                updateAiHeaderStatus(data.conversation.status);
            }
            const news = data.messages || [];
            if (!news.length) return;
            news.forEach(function (msg) {
                aiLastMessageId = Math.max(aiLastMessageId, msg.id || 0);
                if (msg.sender_type === 'user') return;
                const products = (msg.meta && msg.meta.products) ? msg.meta.products : [];
                appendAiBot(msg.message, products, [], msg.sender_type === 'ai' ? msg.id : null);
            });
            aiChatBusy = false;
        })
        .catch(function () { /* ignore */ });
}

function startAiPolling() {
    if (aiPollTimer) return;
    pollAiMessages();
    aiPollTimer = setInterval(pollAiMessages, 3000);
}

function stopAiPolling() {
    if (!aiPollTimer) return;
    clearInterval(aiPollTimer);
    aiPollTimer = null;
}

function updateAiHeaderStatus(status) {
    aiConversationStatus = status || 'ai_active';
    const el = document.getElementById('ai-status-text');
    if (!el) return;
    if (status === 'human_escalated') {
        el.textContent = tJs('ai.status_human', 'Подключён оператор');
    } else if (status === 'closed') {
        el.textContent = tJs('ai.status_closed', 'Диалог завершён');
    } else {
        el.textContent = tJs('ai.status_ai', 'Онлайн · AI-помощник');
    }
}

function aiMessagesEl() {
    return document.getElementById('ai-chat-messages');
}

function appendAiUser(text) {
    const box = aiMessagesEl();
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'flex justify-end';
    const bubble = document.createElement('div');
    bubble.className = 'ai-msg-user max-w-[85%] px-3 py-2 text-[13px] leading-snug whitespace-pre-wrap';
    bubble.textContent = text;
    el.appendChild(bubble);
    box.appendChild(el);
    box.scrollTop = box.scrollHeight;
}

function aiAvatarHtml(sizeClass) {
    const src = window.__aiAvatarUrl || '';
    const src2x = window.__aiAvatarUrl2x || '';
    if (!src) return '';
    const cls = sizeClass || 'w-8 h-8';
    const srcset = src2x ? ' srcset="' + src2x + ' 2x"' : '';
    return '<img src="' + src + '"' + srcset + ' alt="" width="32" height="32" class="' + cls + ' rounded-full object-cover object-top shrink-0 bg-[#E8E6E1] ring-1 ring-[#C9A227]/40 mt-0.5">';
}

function appendAiTyping() {
    const box = aiMessagesEl();
    if (!box) return null;
    const id = 'ai-typing-' + Date.now();
    const el = document.createElement('div');
    el.id = id;
    el.className = 'flex justify-start items-start gap-2';
    el.innerHTML = aiAvatarHtml() + '<div class="ai-msg-bot px-3 py-2 text-[13px] text-ink-700/60 dark:text-gray-400">…</div>';
    box.appendChild(el);
    box.scrollTop = box.scrollHeight;
    return id;
}

function removeAiTyping(id) {
    if (!id) return;
    document.getElementById(id)?.remove();
}

function appendAiBot(text, products, suggestions, msgId) {
    const box = aiMessagesEl();
    if (!box) return;

    const wrap = document.createElement('div');
    wrap.className = 'flex justify-start items-start gap-2';
    if (window.__aiAvatarUrl) {
        const av = document.createElement('img');
        av.src = window.__aiAvatarUrl;
        if (window.__aiAvatarUrl2x) av.srcset = window.__aiAvatarUrl2x + ' 2x';
        av.alt = '';
        av.width = 32;
        av.height = 32;
        av.className = 'w-8 h-8 rounded-full object-cover object-top shrink-0 bg-[#E8E6E1] ring-1 ring-[#C9A227]/40 mt-0.5';
        wrap.appendChild(av);
    }
    const bubble = document.createElement('div');
    bubble.className = 'ai-msg-bot max-w-[95%] px-3 py-2 space-y-2';

    if (text) {
        const p = document.createElement('p');
        p.className = 'text-[13px] leading-snug text-ink-900 dark:text-gray-100 whitespace-pre-wrap';
        p.textContent = text;
        bubble.appendChild(p);
    }

    if (products && products.length) {
        const list = document.createElement('div');
        list.className = 'space-y-1.5 pt-0.5';
        products.forEach(function (item) {
            const a = document.createElement('a');
            a.href = item.url || '#';
            a.className = 'flex gap-2 items-center rounded-xl border border-ink-900/8 dark:border-white/10 bg-brand-50/70 dark:bg-brand-500/10 p-1.5 pr-2 no-underline hover:bg-brand-100/80 dark:hover:bg-brand-500/20 transition';

            if (item.image) {
                const img = document.createElement('img');
                img.src = item.image;
                img.alt = '';
                img.className = 'w-11 h-11 rounded-lg object-cover shrink-0 bg-ink-100';
                a.appendChild(img);
            } else {
                const ph = document.createElement('div');
                ph.className = 'w-11 h-11 rounded-lg shrink-0 bg-ink-100 dark:bg-ink-800 flex items-center justify-center text-brand-500';
                ph.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
                a.appendChild(ph);
            }

            const meta = document.createElement('div');
            meta.className = 'min-w-0 flex-1';
            const title = document.createElement('div');
            title.className = 'text-[12px] font-semibold text-ink-900 dark:text-white truncate';
            title.textContent = item.title || '';
            const price = document.createElement('div');
            price.className = 'text-[11px] text-brand-700 dark:text-brand-400 font-medium truncate';
            price.textContent = item.price || '';
            const sub = document.createElement('div');
            sub.className = 'text-[10px] text-ink-700/55 dark:text-gray-400 truncate';
            const parts = [item.type_label, item.location].filter(Boolean);
            if (item.exchange_for) {
                parts.push('→ ' + item.exchange_for);
            }
            sub.textContent = parts.join(' · ');
            meta.appendChild(title);
            meta.appendChild(price);
            meta.appendChild(sub);
            a.appendChild(meta);
            list.appendChild(a);
        });
        bubble.appendChild(list);
    }

    if (msgId) {
        const csat = document.createElement('div');
        csat.className = 'flex items-center gap-2 pt-1 border-t border-ink-900/5 dark:border-white/5 text-[11px] text-ink-700/60 dark:text-gray-400';
        const label = document.createElement('span');
        label.textContent = tJs('ai.csat_ask', 'Полезен ответ?');
        const up = document.createElement('button');
        up.type = 'button';
        up.className = 'cursor-pointer hover:scale-110 transition';
        up.textContent = '👍';
        up.addEventListener('click', function () { sendAiFeedback(msgId, 5, csat); });
        const down = document.createElement('button');
        down.type = 'button';
        down.className = 'cursor-pointer hover:scale-110 transition';
        down.textContent = '👎';
        down.addEventListener('click', function () { sendAiFeedback(msgId, 1, csat); });
        csat.appendChild(label);
        csat.appendChild(up);
        csat.appendChild(down);
        bubble.appendChild(csat);
    }

    wrap.appendChild(bubble);
    box.appendChild(wrap);
    box.scrollTop = box.scrollHeight;
    renderAiSuggestions(suggestions || []);
}

function sendAiFeedback(msgId, rating, csatEl) {
    fetch(window.__aiFeedbackUrl || '/ai/chat/feedback', {
        method: 'POST',
        headers: aiCsrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
            message_id: msgId,
            rating: rating,
            guest_token: aiGuestToken
        })
    })
        .then(function () {
            if (csatEl) {
                csatEl.textContent = tJs('ai.csat_thanks', 'Спасибо за отзыв!');
                csatEl.className = 'pt-1 text-[11px] text-emerald-600';
            }
        })
        .catch(function () { /* ignore */ });
}

function renderAiSuggestions(suggestions) {
    const row = document.getElementById('ai-chat-suggestions');
    if (!row) return;
    row.innerHTML = '';
    (suggestions || []).forEach(function (s) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'text-[11px] px-2.5 py-1 rounded-lg border border-brand-500/35 bg-brand-50 text-brand-700 hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-200 dark:hover:bg-brand-500/20 transition cursor-pointer';
        btn.textContent = s.label || s.message || '';
        btn.addEventListener('click', function () {
            sendAiSuggestion(s.message || s.label || '');
        });
        row.appendChild(btn);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    updateLiveReturnFab();
    document.getElementById('ai-assistant-toggle')?.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleAiAssistant();
    });
    document.getElementById('ai-assistant-close')?.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleAiAssistant(false);
    });
});

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    const panel = document.getElementById('ai-assistant-panel');
    if (panel && !panel.classList.contains('hidden')) {
        toggleAiAssistant(false);
    }
});

/* ===== Product image lightbox ===== */
(function initImageLightbox() {
    var root = document.getElementById('image-lightbox');
    var img = document.getElementById('image-lightbox-img');
    var counter = document.getElementById('image-lightbox-counter');
    var btnPrev = document.getElementById('image-lightbox-prev');
    var btnNext = document.getElementById('image-lightbox-next');
    var btnClose = document.getElementById('image-lightbox-close');
    if (!root || !img) return;

    var urls = [];
    var index = 0;

    function isOpen() {
        return !root.classList.contains('hidden');
    }

    function render() {
        if (!urls.length) return;
        img.src = urls[index];
        var multi = urls.length > 1;
        btnPrev.classList.toggle('hidden', !multi);
        btnNext.classList.toggle('hidden', !multi);
        if (multi) {
            counter.textContent = (index + 1) + ' / ' + urls.length;
            counter.classList.remove('hidden');
        } else {
            counter.classList.add('hidden');
        }
    }

    function openLightbox(list, start) {
        urls = (list || []).filter(Boolean);
        if (!urls.length) return;
        index = Math.max(0, Math.min(start || 0, urls.length - 1));
        render();
        root.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        if (!isOpen()) return;
        root.classList.add('hidden');
        img.removeAttribute('src');
        urls = [];
        document.body.style.overflow = '';
    }

    function step(delta) {
        if (urls.length < 2) return;
        index = (index + delta + urls.length) % urls.length;
        render();
    }

    window.openImageLightbox = openLightbox;
    window.closeImageLightbox = closeLightbox;

    btnClose?.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        closeLightbox();
    });
    btnPrev?.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        step(-1);
    });
    btnNext?.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        step(1);
    });

    root.addEventListener('click', function (e) {
        if (e.target === root) closeLightbox();
    });

    document.addEventListener('keydown', function (e) {
        if (!isOpen()) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            closeLightbox();
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            step(-1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            step(1);
        }
    });

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-lightbox]');
        if (!trigger) return;
        // Don't steal clicks from nested controls (favorite button sits over the card image)
        if (e.target.closest('.favorite-btn')) return;
        if (e.target.closest('.cart-btn')) return;

        var src = trigger.getAttribute('data-lightbox-src') || '';
        var galleryRaw = trigger.getAttribute('data-lightbox-gallery');
        var list = [];
        if (galleryRaw) {
            try { list = JSON.parse(galleryRaw); } catch (err) { list = []; }
        }
        if (!list.length && src) list = [src];
        if (!list.length) return;

        var start = parseInt(trigger.getAttribute('data-lightbox-index') || '', 10);
        if (isNaN(start) || start < 0) {
            start = src ? list.indexOf(src) : 0;
            if (start < 0) start = 0;
        }

        e.preventDefault();
        e.stopPropagation();
        openLightbox(list, start);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var trigger = e.target.closest('[data-lightbox]');
        if (!trigger || e.target.closest('.favorite-btn')) return;
        e.preventDefault();
        trigger.click();
    });
})();



/* ===== Seller chat drawer ===== */
(function initChatDrawer() {
    const root = document.getElementById("chat-drawer-root");
    const overlay = document.getElementById("chat-drawer-overlay");
    const drawer = document.getElementById("chat-drawer");
    const peerEl = document.getElementById("chat-drawer-peer");
    const productEl = document.getElementById("chat-drawer-product");
    const messagesEl = document.getElementById("chat-drawer-messages");
    const form = document.getElementById("chat-drawer-form");
    const input = document.getElementById("chat-drawer-input");
    const closeBtn = document.getElementById("chat-drawer-close");
    if (!root || !drawer) return;

    let conversationId = 0;
    let lastId = 0;
    let pollTimer = null;
    let open = false;

    function t(key, fallback) {
        return (window.__i18n && window.__i18n[key]) || fallback || key;
    }

    function chatUrl(path) {
        const base = (window.__chatBaseUrl || "/chat/").replace(/\/?$/, "/");
        return base + String(path).replace(/^\//, "");
    }

    function setOpen(next) {
        open = !!next;
        root.setAttribute("aria-hidden", open ? "false" : "true");
        root.classList.toggle("pointer-events-none", !open);
        overlay.classList.toggle("opacity-0", !open);
        overlay.classList.toggle("pointer-events-none", !open);
        overlay.classList.toggle("pointer-events-auto", open);
        drawer.classList.toggle("translate-x-full", !open);
        document.body.classList.toggle("overflow-hidden", open);
        if (!open && pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function scrollBottom() {
        if (messagesEl) messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function escapeHtml(s) {
        return String(s || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function appendMessage(m, clearEmpty) {
        if (!messagesEl || !m || !m.id) return;
        if (messagesEl.querySelector("[data-id=\"" + m.id + "\"]")) return;
        if (clearEmpty) {
            const empty = document.getElementById("chat-drawer-empty");
            if (empty) empty.remove();
        }
        const mine = !!m.is_mine;
        const wrap = document.createElement("div");
        wrap.className = "flex " + (mine ? "justify-end" : "justify-start");
        wrap.dataset.id = String(m.id);
        const time = (m.created_at || "").substr(11, 5);
        const bubble = mine
            ? "bg-brand-600 text-white rounded-br-md"
            : "bg-ink-100 dark:bg-white/10 text-ink-800 dark:text-gray-200 rounded-bl-md";
        const timeCls = mine ? "text-white/60" : "text-gray-400";
        const body = escapeHtml(m.body).replace(/\n/g, "<br>");
        wrap.innerHTML =
            "<div class=\"max-w-[82%] rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed " + bubble + "\">" +
            "<p class=\"whitespace-pre-wrap break-words\">" + body + "</p>" +
            "<p class=\"text-[10px] mt-1 " + timeCls + "\">" + escapeHtml(time) + "</p></div>";
        messagesEl.appendChild(wrap);
        lastId = Math.max(lastId, Number(m.id) || 0);
        scrollBottom();
    }

    function renderThread(data) {
        conversationId = Number(data.conversation_id) || 0;
        lastId = 0;
        if (peerEl) peerEl.textContent = (data.peer && data.peer.name) || t("chat.title", "Chat");
        if (productEl) {
            if (data.product_title) {
                productEl.textContent = data.product_title;
                productEl.classList.remove("hidden");
            } else {
                productEl.textContent = "";
                productEl.classList.add("hidden");
            }
        }
        if (messagesEl) {
            messagesEl.innerHTML = "";
            const list = Array.isArray(data.messages) ? data.messages : [];
            if (!list.length) {
                messagesEl.innerHTML =
                    "<p id=\"chat-drawer-empty\" class=\"text-center text-sm text-gray-400 py-12\">" +
                    escapeHtml(t("chat.start_hint", "Write the first message")) +
                    "</p>";
            } else {
                list.forEach(function (m) { appendMessage(m, false); });
            }
        }
        scrollBottom();
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(poll, 3000);
    }

    async function poll() {
        if (!open || !conversationId) return;
        try {
            const res = await fetch(chatUrl(conversationId + "/poll?after=" + lastId), {
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin"
            });
            if (!res.ok) return;
            const data = await res.json();
            if (!data.ok || !Array.isArray(data.messages)) return;
            data.messages.forEach(function (m) { appendMessage(m, true); });
        } catch (e) {}
    }

    async function openFromStart(payload) {
        if (!window.__isLoggedIn) {
            window.location.href = window.__loginUrl || "/login";
            return;
        }
        setOpen(true);
        if (peerEl) peerEl.textContent = "...";
        if (messagesEl) {
            messagesEl.innerHTML = "<p class=\"text-center text-sm text-gray-400 py-12\">...</p>";
        }
        try {
            const body = new URLSearchParams();
            if (payload.product_id) body.set("product_id", String(payload.product_id));
            if (payload.order_id) body.set("order_id", String(payload.order_id));
            if (payload.user_id) body.set("user_id", String(payload.user_id));
            const res = await fetch(window.__chatStartUrl || chatUrl("start"), {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                credentials: "same-origin",
                body: body.toString()
            });
            const data = await res.json();
            if (!data.ok) {
                alert(data.error || t("chat.start_failed", "Failed to open chat"));
                setOpen(false);
                return;
            }
            renderThread(data);
            input && input.focus();
        } catch (e) {
            alert(t("chat.start_failed", "Failed to open chat"));
            setOpen(false);
        }
    }

    async function openConversation(id) {
        if (!window.__isLoggedIn) {
            window.location.href = window.__loginUrl || "/login";
            return;
        }
        setOpen(true);
        try {
            const res = await fetch(chatUrl(id + "/thread"), {
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin"
            });
            const data = await res.json();
            if (!data.ok) {
                alert(data.error || t("chat.start_failed", "Failed to open chat"));
                setOpen(false);
                return;
            }
            renderThread(data);
            input && input.focus();
        } catch (e) {
            alert(t("chat.start_failed", "Failed to open chat"));
            setOpen(false);
        }
    }

    window.openSellerChat = function (opts) {
        opts = opts || {};
        if (opts.conversation_id) {
            openConversation(opts.conversation_id);
            return;
        }
        openFromStart(opts);
    };

    closeBtn && closeBtn.addEventListener("click", function () { setOpen(false); });
    overlay && overlay.addEventListener("click", function () { setOpen(false); });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && open) setOpen(false);
    });

    document.addEventListener("click", function (e) {
        const btn = e.target.closest("[data-chat-open]");
        if (!btn) return;
        e.preventDefault();
        openSellerChat({
            product_id: btn.getAttribute("data-product-id"),
            order_id: btn.getAttribute("data-order-id"),
            user_id: btn.getAttribute("data-user-id"),
            conversation_id: btn.getAttribute("data-conversation-id")
        });
    });

    form && form.addEventListener("submit", async function (e) {
        e.preventDefault();
        const text = (input && input.value || "").trim();
        if (!text || !conversationId) return;
        input.value = "";
        input.style.height = "auto";
        try {
            const body = new URLSearchParams({ body: text });
            const res = await fetch(chatUrl(conversationId + "/send"), {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                credentials: "same-origin",
                body: body.toString()
            });
            const data = await res.json();
            if (data.ok && data.message) appendMessage(data.message, true);
            else if (data.error) alert(data.error);
        } catch (err) {
            alert(t("chat.send_failed", "Failed to send"));
        }
    });

    input && input.addEventListener("keydown", function (e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            form && form.requestSubmit();
        }
    });

    input && input.addEventListener("input", function () {
        this.style.height = "auto";
        this.style.height = Math.min(this.scrollHeight, 112) + "px";
    });
})();
