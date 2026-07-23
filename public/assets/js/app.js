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
    ['story-viewer', 'stream-viewer', 'story-create-modal'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el && el.parentElement !== document.body) {
            document.body.appendChild(el);
        }
    });
}
document.addEventListener('DOMContentLoaded', portalStoryModals);
portalStoryModals();

let storyGroupIndex = 0;
let storyItemIndex = 0;
let storyTimer = null;
const STORY_DURATION = 5000;

function openStoryCreate() {
    document.getElementById('story-create-modal')?.classList.remove('hidden');
}

function closeStoryCreate() {
    document.getElementById('story-create-modal')?.classList.add('hidden');
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
        bg = 'background-image:url(\'' + (window.__storyUploadBase || '') + s.image + '\')';
    } else {
        const c1 = s.bg_color || '#2563EB';
        bg = 'background:linear-gradient(160deg,' + c1 + ',#111)';
        emoji = '<span class="story-peek-emoji">' + (s.emoji || '✨') + '</span>';
    }
    return '<div class="story-peek-bg" style="' + bg + '"></div>' + emoji +
        '<span class="story-peek-name">' + (group.user_name || '') + '</span>';
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
        avatarEl.innerHTML = '<img src="' + group.avatar_url + '" alt="" class="w-full h-full object-cover">';
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
    // не останавливаем камеру хоста, если эфир ещё идёт (только при закрытии зрителем чужого эфира)
    const cam = document.getElementById('stream-live-cam');
    if (cam && !window.__myLiveId) {
        stopLiveCamera();
    }
    document.getElementById('stream-viewer')?.classList.add('hidden');
    document.getElementById('stream-paused')?.classList.add('hidden');
    document.body.style.overflow = '';
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

    iframe.classList.add('hidden');
    iframe.src = '';
    livePanel.classList.add('hidden');
    video.classList.add('hidden');
    endBtn.classList.add('hidden');
    if (cam && Number(stream.user_id) !== Number(window.__currentUserId)) {
        cam.classList.add('hidden');
    }

    const isHost = stream.is_live && Number(stream.user_id) === Number(window.__currentUserId);

    if (stream.is_live) {
        livePanel.classList.remove('hidden');
        document.getElementById('stream-live-avatar').textContent = stream.author_avatar || '?';
        document.getElementById('stream-live-host').textContent = stream.author_name || window.__i18n?.['js.live_host'] || 'Эфир';
        if (isHost) {
            endBtn.classList.remove('hidden');
            startLiveCameraPreview();
            cam.classList.remove('hidden');
        } else {
            stopLiveCamera();
            cam.classList.add('hidden');
        }
        animateFakeProgress(30000);
    } else {
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

/* ===== Live (не хранится) ===== */
let liveHeartbeatTimer = null;
window.__myLiveId = null;
let liveMediaStream = null;

function startLiveStream() {
    if (!window.__currentUserId) {
        alert(window.__i18n?.['js.login_to_stream'] || 'Войдите, чтобы начать эфир');
        return;
    }
    fetch(window.__streamLiveStart, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) {
                alert(data.message || window.__i18n?.['js.stream_fail'] || 'Не удалось начать эфир');
                return;
            }
            window.__myLiveId = data.id;
            startLiveHeartbeat(data.id);
            startLiveCameraPreview();

            // добавить в ленту локально и открыть
            const me = {
                id: data.id,
                user_id: window.__currentUserId,
                title: data.title,
                description: window.__i18n?.['js.stream_desc'] || 'Прямой эфир — не сохраняется',
                author_name: window.__i18n?.['js.you'] || 'Вы',
                author_avatar: '●',
                is_live: true,
                file: null,
                url: null,
                embed: null,
                cover: null
            };
            window.__streams = window.__streams || [];
            window.__streams.unshift(me);
            openStreamViewer(0);
        })
        .catch(function () {
            alert(window.__i18n?.['js.stream_error'] || 'Ошибка старта эфира');
        });
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
        // чтобы карточка исчезла у всех — обновляем страницу
        location.reload();
    });
}

function startLiveCameraPreview() {
    const cam = document.getElementById('stream-live-cam');
    if (!cam || !navigator.mediaDevices?.getUserMedia) return;
    if (liveMediaStream) {
        cam.srcObject = liveMediaStream;
        cam.classList.remove('hidden');
        cam.play().catch(function () {});
        return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: true })
        .then(function (stream) {
            liveMediaStream = stream;
            cam.srcObject = stream;
            cam.classList.remove('hidden');
            cam.muted = true;
            cam.play().catch(function () {});
        })
        .catch(function () {
            // камера недоступна — эфир всё равно считается активным (метка Live)
        });
}

function stopLiveCamera() {
    if (liveMediaStream) {
        liveMediaStream.getTracks().forEach(function (t) { t.stop(); });
        liveMediaStream = null;
    }
    const cam = document.getElementById('stream-live-cam');
    if (cam) {
        cam.srcObject = null;
        cam.classList.add('hidden');
    }
}

window.addEventListener('beforeunload', function () {
    if (window.__myLiveId) {
        const params = new URLSearchParams({ id: String(window.__myLiveId) });
        if (navigator.sendBeacon) {
            navigator.sendBeacon(window.__streamLiveEnd, params);
        }
        stopLiveCamera();
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
    if (video) video.muted = streamMuted;
    updateMuteBtn();
}

function updateMuteBtn() {
    const btn = document.getElementById('stream-mute-btn');
    if (!btn) return;
    btn.innerHTML = streamMuted
        ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>'
        : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>';
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

    const hold = document.getElementById('stream-hold-zone');
    if (!hold) return;

    const startHold = function (e) {
        if (e.target.closest('#stream-tap-prev, #stream-tap-next, button, form, a')) return;
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

/* ===== AI Assistant ===== */
let aiAssistantReady = false;
let aiChatBusy = false;

function tJs(key, fallback) {
    return (window.__i18n && window.__i18n[key]) || fallback;
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
        setTimeout(function () {
            document.getElementById('ai-chat-input')?.focus();
        }, 40);
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

    appendAiBot(
        tJs('ai.welcome', 'Привет! Я помощник Zakopeyki. Ищу товары и услуги в каталоге. Что нужно?'),
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

function sendAiSuggestion(message) {
    sendAiMessage(message);
}

function sendAiMessage(text) {
    if (aiChatBusy || !text) return;
    aiChatBusy = true;

    appendAiUser(text);
    renderAiSuggestions([]);
    const typingId = appendAiTyping();

    fetch(window.__aiChatUrl || '/ai/chat', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify({ message: text })
    })
        .then(function (r) {
            return r.json().catch(function () {
                return { ok: false, reply: tJs('ai.error_reply', 'Не удалось получить ответ.') };
            });
        })
        .then(function (data) {
            removeAiTyping(typingId);
            if (!data || data.ok === false) {
                appendAiBot(data?.reply || tJs('ai.error_reply', 'Не удалось получить ответ. Попробуйте ещё раз.'), [], []);
                return;
            }
            appendAiBot(data.reply || '', data.products || [], data.suggestions || []);
        })
        .catch(function () {
            removeAiTyping(typingId);
            appendAiBot(tJs('ai.error_network', 'Сеть недоступна. Проверьте соединение и повторите.'), [], []);
        })
        .finally(function () {
            aiChatBusy = false;
        });
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

function appendAiTyping() {
    const box = aiMessagesEl();
    if (!box) return null;
    const id = 'ai-typing-' + Date.now();
    const el = document.createElement('div');
    el.id = id;
    el.className = 'flex justify-start';
    el.innerHTML = '<div class="ai-msg-bot px-3 py-2 text-[13px] text-ink-700/60 dark:text-gray-400">…</div>';
    box.appendChild(el);
    box.scrollTop = box.scrollHeight;
    return id;
}

function removeAiTyping(id) {
    if (!id) return;
    document.getElementById(id)?.remove();
}

function appendAiBot(text, products, suggestions) {
    const box = aiMessagesEl();
    if (!box) return;

    const wrap = document.createElement('div');
    wrap.className = 'flex justify-start';
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

    wrap.appendChild(bubble);
    box.appendChild(wrap);
    box.scrollTop = box.scrollHeight;
    renderAiSuggestions(suggestions || []);
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

