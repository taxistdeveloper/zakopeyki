<?php

use App\Helpers\ProductHelper;

$heroUrl = ProductHelper::url('/public/assets/img/stub-hero.jpg');
$opensAt = $opensAt ?? '2026-08-30 00:00:00';
$opensTs = strtotime($opensAt) ?: (time() + 7 * 86400);
$opensIso = date('Y-m-d\TH:i:sP', $opensTs);
$loginUrl = ProductHelper::url('/login');
$logoutUrl = ProductHelper::url('/logout');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title><?= htmlspecialchars($title ?? 'Скоро открытие') ?> — Zakopeyki.kz</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet" />
  <style>
*,
*::before,
*::after {
  box-sizing: border-box;
}

html,
body {
  margin: 0;
  padding: 0;
  width: 100%;
  height: 100%;
  background: #12021F;
}

body {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  overflow: hidden;
  font-family: "Montserrat", system-ui, sans-serif;
  -webkit-font-smoothing: antialiased;
}

.stage {
  width: min(100vw, 1920px);
  height: min(100vh, 1080px);
  aspect-ratio: 16 / 9;
  max-width: 1920px;
  max-height: 1080px;
  background: #12021F;
  position: relative;
}

@media (min-width: 1920px) and (min-height: 1080px) {
  .stage {
    width: 1920px;
    height: 1080px;
    aspect-ratio: auto;
  }
}

.hero {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #1A0528;
  overflow: hidden;
}

.art {
  position: relative;
  width: 100%;
  aspect-ratio: 1920 / 1003;
  max-height: 100%;
  line-height: 0;
}

.art-img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: fill;
  pointer-events: none;
  user-select: none;
  -webkit-user-drag: none;
}

.hit {
  position: absolute;
  margin: 0;
  padding: 0;
  border: 2px solid transparent;
  background: transparent;
  cursor: pointer;
  z-index: 2;
  -webkit-tap-highlight-color: transparent;
}

.countdown-slot {
  position: absolute;
  left: 52.1%;
  transform: translateX(-50%);
  z-index: 3;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  overflow: hidden;
}

.countdown {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: clamp(0.1rem, 0.4vw, 0.35rem);
  width: 100%;
  height: 100%;
  padding: 1% 2%;
  text-align: center;
}

.cd-title {
  margin: 0;
  font-size: clamp(0.55rem, 1.15vw, 1rem);
  font-weight: 600;
  color: #fff;
  letter-spacing: 0.01em;
  line-height: 1.2;
  white-space: nowrap;
}

.cd-note {
  margin: 0;
  font-size: clamp(0.45rem, 0.9vw, 0.78rem);
  font-weight: 600;
  color: rgba(255, 255, 255, 0.92);
  letter-spacing: 0.02em;
  line-height: 1.2;
  white-space: nowrap;
}

.cd-row {
  display: flex;
  align-items: flex-start;
  justify-content: center;
  gap: clamp(0.15rem, 0.7vw, 0.55rem);
}

.cd-unit {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  min-width: 0;
  line-height: 1;
}

.cd-num {
  font-size: clamp(1.15rem, 2.85vw, 2.55rem);
  font-weight: 800;
  letter-spacing: 0.02em;
  line-height: 1;
  font-variant-numeric: tabular-nums;
  background: linear-gradient(180deg, #FFE566 0%, #FFD400 42%, #E8A800 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  -webkit-text-fill-color: transparent;
  filter: drop-shadow(0 1px 0 rgba(80, 40, 0, 0.35));
}

.cd-lbl {
  margin-top: 0.35em;
  font-size: clamp(0.4rem, 0.78vw, 0.68rem);
  font-weight: 600;
  text-transform: uppercase;
  color: #fff;
  letter-spacing: 0.06em;
  white-space: nowrap;
}

.cd-sep {
  font-size: clamp(1.05rem, 2.5vw, 2.2rem);
  font-weight: 800;
  line-height: 1;
  padding-top: 0.05em;
  background: linear-gradient(180deg, #FFE566 0%, #FFD400 42%, #E8A800 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  -webkit-text-fill-color: transparent;
  animation: cd-blink 1s steps(1, end) infinite;
}

@keyframes cd-blink {
  0%, 45% { opacity: 0.95; }
  50%, 100% { opacity: 0.35; }
}

.countdown.is-done .cd-sep {
  animation: none;
  opacity: 0.6;
}

@media (prefers-reduced-motion: reduce) {
  .cd-sep {
    animation: none;
  }
}

.hit-cat {
  border-radius: 22px;
}

.hit-cat:hover,
.hit-cat:focus-visible {
  outline: none;
  border-color: #FFD400;
  box-shadow: 0 0 0 1px rgba(255, 212, 0, 0.5);
  background: rgba(255, 255, 255, 0.06);
}

.hit-cat.is-active {
  border-color: #F5C518;
  box-shadow: 0 0 0 2px rgba(245, 197, 24, 0.45);
  background: rgba(245, 197, 24, 0.08);
}

.toast {
  position: fixed;
  left: 50%;
  bottom: 1.5rem;
  transform: translateX(-50%) translateY(140%);
  z-index: 50;
  padding: 0.75rem 1.4rem;
  border-radius: 999px;
  background: #1A0528;
  color: #FFE566;
  font-weight: 700;
  font-size: 1rem;
  border: 1px solid rgba(245, 197, 24, 0.45);
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.45);
  transition: transform 0.25s ease, opacity 0.25s ease;
  opacity: 0;
  pointer-events: none;
  max-width: calc(100vw - 2rem);
  text-align: center;
  line-height: 1.35;
  will-change: transform, opacity;
}

.toast.is-visible {
  transform: translateX(-50%) translateY(0);
  opacity: 1;
}

.login-btn {
  position: fixed;
  top: 12px;
  right: 12px;
  z-index: 60;
  font-family: "Montserrat", system-ui, sans-serif;
  font-size: 12px;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.65);
  text-decoration: none;
  padding: 6px 14px;
  border-radius: 999px;
  border: 1px solid rgba(255, 212, 0, 0.35);
  background: rgba(18, 2, 31, 0.55);
  backdrop-filter: blur(6px);
  cursor: pointer;
  transition: color 0.2s, background 0.2s;
}

.login-btn:hover {
  color: #FFE566;
  background: rgba(18, 2, 31, 0.8);
}

body.debug .hit {
  outline: 1px dashed #00ff88;
  outline-offset: -1px;
  background: rgba(0, 255, 120, 0.12);
}

@media (max-width: 768px) {
  body {
    overflow: auto;
    display: block;
  }

  .stage {
    width: 100%;
    height: auto;
    aspect-ratio: 16 / 9;
    max-height: none;
  }
}
  </style>
</head>
<body>
  <?php if (\App\Core\Auth::check()): ?>
    <form method="post" action="<?= htmlspecialchars($logoutUrl) ?>" style="position:fixed;top:12px;right:12px;z-index:60;">
      <?= \App\Core\Csrf::field() ?>
      <button type="submit" class="login-btn" style="position:static;">Выйти</button>
    </form>
  <?php else: ?>
    <a class="login-btn" href="<?= htmlspecialchars($loginUrl) ?>">Вход</a>
  <?php endif; ?>

  <main class="stage">
    <section class="hero" aria-label="Zakopeyki.kz">
      <div class="art">
        <img
          src="<?= htmlspecialchars($heroUrl) ?>"
          width="1920"
          height="1003"
          alt="Zakopeyki.kz — Антикризисная платформа № 1 в Казахстане"
          class="art-img"
          draggable="false"
          decoding="async"
          fetchpriority="high"
        />

        <div
          class="countdown-slot"
          style="top:51.3%; width:26.5%; height:13.2%"
          aria-live="polite"
        >
          <div
            id="countdown"
            class="countdown"
            data-end="<?= htmlspecialchars($opensIso) ?>"
            role="timer"
            aria-label="До открытия осталось"
          >
            <p class="cd-title">До открытия осталось:</p>
            <div class="cd-row">
              <div class="cd-unit">
                <span class="cd-num" data-days>00</span>
                <span class="cd-lbl">дней</span>
              </div>
              <span class="cd-sep" aria-hidden="true">:</span>
              <div class="cd-unit">
                <span class="cd-num" data-hours>00</span>
                <span class="cd-lbl">часов</span>
              </div>
              <span class="cd-sep" aria-hidden="true">:</span>
              <div class="cd-unit">
                <span class="cd-num" data-mins>00</span>
                <span class="cd-lbl">минут</span>
              </div>
              <span class="cd-sep" aria-hidden="true">:</span>
              <div class="cd-unit">
                <span class="cd-num" data-secs>00</span>
                <span class="cd-lbl">секунд</span>
              </div>
            </div>
            <p class="cd-note">30 августа откроется сайт</p>
          </div>
        </div>

        <nav id="categories" aria-label="Категории">
          <button type="button" class="hit hit-cat" style="left:15.00%; top:71.00%; width:8.75%; height:19.00%" data-label="Товары new" aria-label="Товары new"></button>
          <button type="button" class="hit hit-cat" style="left:24.53%; top:71.00%; width:8.75%; height:19.00%" data-label="Товары Б/у" aria-label="Товары Б/у"></button>
          <button type="button" class="hit hit-cat" style="left:33.85%; top:71.00%; width:8.75%; height:19.00%" data-label="Аукционы" aria-label="Аукционы"></button>
          <button type="button" class="hit hit-cat" style="left:43.28%; top:71.00%; width:8.75%; height:19.00%" data-label="Услуги" aria-label="Услуги"></button>
          <button type="button" class="hit hit-cat" style="left:52.71%; top:71.00%; width:8.75%; height:19.00%" data-label="Биржа услуг" aria-label="Биржа услуг"></button>
          <button type="button" class="hit hit-cat" style="left:62.14%; top:71.00%; width:8.75%; height:19.00%" data-label="Курсы" aria-label="Курсы"></button>
          <button type="button" class="hit hit-cat" style="left:71.56%; top:71.00%; width:8.75%; height:19.00%" data-label="Обмен" aria-label="Обмен"></button>
          <button type="button" class="hit hit-cat" style="left:80.99%; top:71.00%; width:8.75%; height:19.00%" data-label="Даром" aria-label="Даром"></button>
        </nav>
      </div>
    </section>
  </main>

  <div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
  <script>
function showToast(message) {
  const toast = document.getElementById('toast');
  if (!toast) return;
  toast.textContent = message;
  toast.hidden = false;
  requestAnimationFrame(() => toast.classList.add('is-visible'));
  clearTimeout(showToast._t);
  showToast._t = setTimeout(() => {
    toast.classList.remove('is-visible');
    setTimeout(() => {
      toast.hidden = true;
    }, 250);
  }, 2000);
}

function pad2(n) {
  return String(n).padStart(2, '0');
}

function initCountdown() {
  const root = document.getElementById('countdown');
  if (!root) return;

  const endAttr = root.dataset.end;
  const end = endAttr ? new Date(endAttr).getTime() : Date.now() + 7 * 24 * 60 * 60 * 1000;

  const daysEl = root.querySelector('[data-days]');
  const hoursEl = root.querySelector('[data-hours]');
  const minsEl = root.querySelector('[data-mins]');
  const secsEl = root.querySelector('[data-secs]');

  function tick() {
    const diff = Math.max(0, end - Date.now());
    const totalSec = Math.floor(diff / 1000);
    const days = Math.floor(totalSec / 86400);
    const hours = Math.floor((totalSec % 86400) / 3600);
    const mins = Math.floor((totalSec % 3600) / 60);
    const secs = totalSec % 60;

    daysEl.textContent = pad2(days);
    hoursEl.textContent = pad2(hours);
    minsEl.textContent = pad2(mins);
    secsEl.textContent = pad2(secs);

    if (diff <= 0) {
      root.classList.add('is-done');
      return false;
    }
    return true;
  }

  if (!tick()) return;
  const id = setInterval(() => {
    if (!tick()) clearInterval(id);
  }, 1000);
}

document.addEventListener('DOMContentLoaded', () => {
  if (new URLSearchParams(location.search).get('debug') === '1') {
    document.body.classList.add('debug');
  }

  initCountdown();

  const nav = document.getElementById('categories');
  nav?.addEventListener('click', (e) => {
    const btn = e.target.closest('.hit-cat');
    if (!btn) return;
    nav.querySelectorAll('.hit-cat').forEach((el) => el.classList.remove('is-active'));
    btn.classList.add('is-active');
    showToast(`Раздел: ${btn.dataset.label}`);
  });
});
  </script>
</body>
</html>
