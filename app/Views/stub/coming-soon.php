<?php

use App\Helpers\ProductHelper;

$heroUrl = ProductHelper::url('/public/assets/img/stub-hero.jpg');
$opensAt = $opensAt ?? '2026-09-30 00:00:00';
$opensTs = strtotime($opensAt) ?: (time() + 7 * 86400);
$opensIso = date('Y-m-d\TH:i:sP', $opensTs);
$loginUrl = ProductHelper::url('/login');
$registerUrl = ProductHelper::url('/register');
$logoutUrl = ProductHelper::url('/logout');
$stubFlash = $_SESSION['stub_flash'] ?? null;
unset($_SESSION['stub_flash']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="robots" content="noindex,nofollow" />
  <meta name="theme-color" content="#12021F" />
  <title><?= htmlspecialchars($title ?? 'Скоро открытие') ?> — Zakopeyki.kz</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet" />
  <style>
*,
*::before,
*::after {
  box-sizing: border-box;
}

html {
  margin: 0;
  padding: 0;
  width: 100%;
  background: #12021F;
  -webkit-text-size-adjust: 100%;
  text-size-adjust: 100%;
}

body {
  margin: 0;
  padding: 0;
  width: 100%;
  min-height: 100vh;
  min-height: 100dvh;
  background: #12021F;
  font-family: "Montserrat", system-ui, sans-serif;
  -webkit-font-smoothing: antialiased;
  overflow-x: hidden;
  overflow-y: auto;
}

.stage {
  width: 100%;
  max-width: 1920px;
  margin: 0 auto;
  min-height: 100vh;
  min-height: 100dvh;
  background: #12021F;
  display: flex;
  align-items: center;
  justify-content: center;
  padding:
    max(0px, env(safe-area-inset-top))
    max(0px, env(safe-area-inset-right))
    max(0px, env(safe-area-inset-bottom))
    max(0px, env(safe-area-inset-left));
}

.hero {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #1A0528;
}

.art {
  position: relative;
  width: 100%;
  aspect-ratio: 1920 / 1003;
  line-height: 0;
  container-type: inline-size;
  container-name: art;
}

.art-frame {
  position: absolute;
  inset: 0;
  line-height: 0;
}

.art-split {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.art-slice--top {
  display: none;
}

.art-slice--bot {
  position: absolute;
  inset: 0;
  pointer-events: none;
  line-height: 0;
}

.art-slice--bot .art-img {
  display: none;
}

.art-slice--bot #categories {
  pointer-events: auto;
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

.art-img--full {
  position: absolute;
  inset: 0;
}

/* Desktop / large: fit art into viewport without crop */
@media (min-width: 900px) {
  body {
    overflow: hidden;
    height: 100vh;
    height: 100dvh;
  }

  .stage {
    height: 100vh;
    height: 100dvh;
    min-height: 0;
  }

  .hero {
    height: 100%;
  }

  .art {
    width: min(100%, calc((100dvh - env(safe-area-inset-top) - env(safe-area-inset-bottom)) * 1920 / 1003));
    max-height: calc(100dvh - env(safe-area-inset-top) - env(safe-area-inset-bottom));
  }
}

/* Short landscape phones / tablets */
@media (orientation: landscape) and (max-height: 560px) {
  body {
    overflow: hidden;
    height: 100dvh;
  }

  .stage {
    height: 100dvh;
    min-height: 0;
    padding: 0;
  }

  .hero {
    height: 100%;
  }

  .art {
    width: auto;
    height: 100dvh;
    max-height: 100dvh;
    aspect-ratio: 1920 / 1003;
  }
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
  touch-action: manipulation;
}

.countdown-slot {
  position: absolute;
  left: 52.1%;
  top: 56%;
  width: 34%;
  height: 30%;
  transform: translate(-50%, -50%);
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
  gap: clamp(0.2rem, 0.7cqw, 0.7rem);
  width: 100%;
  height: 100%;
  padding: 4% 3%;
  text-align: center;
}

.cd-title {
  margin: 0;
  font-size: clamp(0.5rem, 1.15cqw, 1rem);
  font-weight: 600;
  color: #fff;
  letter-spacing: 0.01em;
  line-height: 1.2;
  white-space: nowrap;
}

.cd-note {
  margin: 0;
  font-size: clamp(0.4rem, 0.9cqw, 0.78rem);
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
  gap: clamp(0.1rem, 0.55cqw, 0.55rem);
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
  font-size: clamp(0.85rem, 2.85cqw, 2.55rem);
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
  font-size: clamp(0.35rem, 0.78cqw, 0.68rem);
  font-weight: 600;
  text-transform: uppercase;
  color: #fff;
  letter-spacing: 0.06em;
  white-space: nowrap;
}

.cd-sep {
  font-size: clamp(0.75rem, 2.5cqw, 2.2rem);
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

  .toast {
    transition: none;
  }
}

.hit-cat {
  border-radius: clamp(8px, 1.2cqw, 22px);
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
  bottom: max(1rem, env(safe-area-inset-bottom, 0px));
  transform: translateX(-50%) translateY(140%);
  z-index: 50;
  padding: 0.7rem 1.2rem;
  border-radius: 999px;
  background: #1A0528;
  color: #FFE566;
  font-weight: 700;
  font-size: clamp(0.85rem, 2.8vw, 1rem);
  border: 1px solid rgba(245, 197, 24, 0.45);
  box-shadow: 0 10px 28px rgba(0, 0, 0, 0.45);
  transition: transform 0.25s ease, opacity 0.25s ease;
  opacity: 0;
  pointer-events: none;
  max-width: min(28rem, calc(100vw - 2rem - env(safe-area-inset-left) - env(safe-area-inset-right)));
  text-align: center;
  line-height: 1.35;
  will-change: transform, opacity;
}

.toast.is-visible {
  transform: translateX(-50%) translateY(0);
  opacity: 1;
}

.auth-bar {
  position: fixed;
  top: max(12px, env(safe-area-inset-top, 0px));
  right: max(12px, env(safe-area-inset-right, 0px));
  z-index: 60;
  display: flex;
  align-items: center;
  gap: 8px;
}

.login-btn {
  font-family: "Montserrat", system-ui, sans-serif;
  font-size: 12px;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.65);
  text-decoration: none;
  padding: 8px 14px;
  border-radius: 999px;
  border: 1px solid rgba(255, 212, 0, 0.35);
  background: rgba(18, 2, 31, 0.55);
  backdrop-filter: blur(6px);
  cursor: pointer;
  transition: color 0.2s, background 0.2s;
  -webkit-tap-highlight-color: transparent;
  appearance: none;
}

.login-btn--accent {
  color: #12021F;
  background: linear-gradient(180deg, #FFE566 0%, #FFD400 55%, #E8A800 100%);
  border-color: rgba(255, 212, 0, 0.8);
}

.login-btn:hover,
.login-btn:focus-visible {
  color: #FFE566;
  background: rgba(18, 2, 31, 0.8);
  outline: none;
}

.login-btn--accent:hover,
.login-btn--accent:focus-visible {
  color: #12021F;
  filter: brightness(1.06);
  background: linear-gradient(180deg, #FFE566 0%, #FFD400 55%, #E8A800 100%);
}

.cta-modal {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: none;
  align-items: center;
  justify-content: center;
  padding:
    max(1rem, env(safe-area-inset-top))
    max(1rem, env(safe-area-inset-right))
    max(1rem, env(safe-area-inset-bottom))
    max(1rem, env(safe-area-inset-left));
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition: opacity 0.28s ease, visibility 0.28s ease;
}

.cta-modal.is-open {
  display: flex;
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
}

.cta-modal[hidden] {
  display: none !important;
}

.cta-modal.is-open:not([hidden]) {
  display: flex !important;
}

.cta-modal__backdrop {
  position: absolute;
  inset: 0;
  border: 0;
  padding: 0;
  margin: 0;
  background: rgba(8, 0, 18, 0.72);
  backdrop-filter: blur(6px);
  cursor: pointer;
}

.cta-modal__dialog {
  position: relative;
  z-index: 1;
  width: min(100%, 26rem);
  transform: translateY(18px) scale(0.96);
  transition: transform 0.32s cubic-bezier(0.22, 1, 0.36, 1);
}

.cta-modal.is-open .cta-modal__dialog {
  transform: translateY(0) scale(1);
}

.cta-modal__card {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.65rem;
  padding: 1.65rem 1.4rem 1.45rem;
  text-align: center;
  border-radius: 1.4rem;
  background:
    linear-gradient(165deg, rgba(86, 22, 128, 0.92) 0%, rgba(26, 5, 40, 0.96) 48%, rgba(14, 1, 24, 0.98) 100%);
  border: 1px solid rgba(255, 212, 0, 0.55);
  box-shadow:
    0 0 0 1px rgba(180, 60, 220, 0.25) inset,
    0 24px 60px rgba(0, 0, 0, 0.55),
    0 0 48px rgba(168, 50, 220, 0.28);
  overflow: hidden;
}

.cta-modal__card::before {
  content: "";
  position: absolute;
  inset: 0;
  background: linear-gradient(
    115deg,
    transparent 0%,
    transparent 40%,
    rgba(255, 230, 140, 0.14) 50%,
    transparent 60%,
    transparent 100%
  );
  background-size: 220% 100%;
  animation: cta-sheen 4.5s ease-in-out infinite;
  pointer-events: none;
}

.cta-modal__card::after {
  content: "";
  position: absolute;
  left: 12%;
  right: 12%;
  top: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(255, 230, 150, 0.8), transparent);
  pointer-events: none;
}

.cta-modal__close {
  position: absolute;
  top: 0.7rem;
  right: 0.7rem;
  z-index: 2;
  width: 2rem;
  height: 2rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.06);
  color: rgba(255, 255, 255, 0.75);
  font-size: 1.15rem;
  line-height: 1;
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
}

.cta-modal__close:hover,
.cta-modal__close:focus-visible {
  background: rgba(255, 255, 255, 0.14);
  color: #FFE566;
  outline: none;
}

.cta-modal__eyebrow {
  position: relative;
  z-index: 1;
  margin: 0;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: #FFE566;
}

.cta-modal__title {
  position: relative;
  z-index: 1;
  margin: 0;
  font-size: clamp(1.25rem, 4vw, 1.55rem);
  font-weight: 800;
  color: #fff;
  line-height: 1.2;
  letter-spacing: -0.02em;
}

.cta-modal__sub {
  position: relative;
  z-index: 1;
  margin: 0;
  max-width: 22em;
  font-size: 0.9rem;
  font-weight: 500;
  color: rgba(255, 255, 255, 0.78);
  line-height: 1.45;
}

.cta-modal__btn {
  position: relative;
  z-index: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45em;
  width: 100%;
  min-height: 2.85rem;
  margin-top: 0.35rem;
  padding: 0.65em 1.4em;
  border-radius: 999px;
  border: 1px solid rgba(255, 240, 170, 0.95);
  background: linear-gradient(180deg, #FFF1A0 0%, #FFD400 42%, #E8A800 100%);
  color: #1A0528;
  font-family: inherit;
  font-size: 0.98rem;
  font-weight: 800;
  letter-spacing: 0.02em;
  text-decoration: none;
  box-shadow:
    0 1px 0 rgba(255, 255, 255, 0.45) inset,
    0 8px 22px rgba(0, 0, 0, 0.3),
    0 0 26px rgba(255, 212, 0, 0.35);
  transition: transform 0.22s ease, filter 0.22s ease, box-shadow 0.22s ease;
  overflow: hidden;
  -webkit-tap-highlight-color: transparent;
}

.cta-modal__btn::before {
  content: "";
  position: absolute;
  top: 0;
  left: -40%;
  width: 40%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.45), transparent);
  transform: skewX(-20deg);
  animation: cta-btn-shine 3.2s ease-in-out infinite;
}

.cta-modal__btn:hover,
.cta-modal__btn:focus-visible {
  transform: translateY(-2px);
  filter: brightness(1.06);
  outline: none;
  box-shadow:
    0 1px 0 rgba(255, 255, 255, 0.5) inset,
    0 12px 28px rgba(0, 0, 0, 0.35),
    0 0 36px rgba(255, 212, 0, 0.5);
}

.cta-modal__btn:active {
  transform: translateY(0) scale(0.98);
}

.cta-modal__arrow {
  display: inline-block;
  transition: transform 0.22s ease;
}

.cta-modal__btn:hover .cta-modal__arrow,
.cta-modal__btn:focus-visible .cta-modal__arrow {
  transform: translateX(3px);
}

.cta-modal__hint {
  position: relative;
  z-index: 1;
  margin: 0.15rem 0 0;
  font-size: 0.72rem;
  font-weight: 500;
  color: rgba(255, 255, 255, 0.45);
}

@keyframes cta-sheen {
  0%, 100% { background-position: 140% 0; }
  50% { background-position: -40% 0; }
}

@keyframes cta-btn-shine {
  0%, 55% { left: -40%; }
  80%, 100% { left: 130%; }
}

@media (prefers-reduced-motion: reduce) {
  .cta-modal,
  .cta-modal__dialog {
    transition: none;
  }

  .cta-modal__card::before,
  .cta-modal__btn::before {
    animation: none;
  }
}

body.debug .hit {
  outline: 1px dashed #00ff88;
  outline-offset: -1px;
  background: rgba(0, 255, 120, 0.12);
}

/* Tablet */
@media (max-width: 899px) {
  .countdown-slot {
    top: 56%;
    width: 36%;
    height: 32%;
    transform: translate(-50%, -50%);
  }

  .cd-title {
    font-size: clamp(0.62rem, 2cqw, 1.05rem);
  }

  .cd-num {
    font-size: clamp(1.05rem, 4.8cqw, 2.4rem);
  }

  .cd-sep {
    font-size: clamp(0.95rem, 4.2cqw, 2.1rem);
  }

  .cd-lbl {
    font-size: clamp(0.42rem, 1.35cqw, 0.72rem);
    letter-spacing: 0.04em;
  }

  .cd-note {
    font-size: clamp(0.48rem, 1.5cqw, 0.8rem);
  }
}

/* Mobile portrait — отдельная читаемая вёрстка */
@media (max-width: 767px) {
  body {
    overflow-x: hidden;
    overflow-y: auto;
    height: auto;
  }

  .stage {
    min-height: 100dvh;
    height: auto;
    align-items: stretch;
    justify-content: flex-start;
    padding:
      max(0px, env(safe-area-inset-top))
      0
      max(0.75rem, env(safe-area-inset-bottom))
      0;
  }

  .hero {
    width: 100%;
    flex-direction: column;
    align-items: stretch;
  }

  .art {
    aspect-ratio: unset;
    width: 100%;
    display: flex;
    flex-direction: column;
    container-type: normal;
  }

  .art-frame {
    position: relative;
    inset: auto;
    width: 100%;
    aspect-ratio: unset;
    flex-shrink: 0;
  }

  .art-img--full {
    display: none;
  }

  .art-split {
    position: relative;
    inset: auto;
    display: flex;
    flex-direction: column;
    pointer-events: auto;
  }

  .art-slice--top {
    display: block;
    position: relative;
    overflow: hidden;
    width: 100%;
    /* верх баннера до рамки (~0–47.5%) */
    height: calc(100vw * 1003 / 1920 * 0.475);
  }

  .art-slice--top .art-img {
    display: block;
    width: 100%;
    height: auto;
    position: absolute;
    left: 0;
    top: 0;
    object-fit: fill;
  }

  .art-slice--bot {
    position: relative;
    inset: auto;
    overflow: hidden;
    width: 100%;
    /* низ баннера после рамки (~66.5–100%) */
    height: calc(100vw * 1003 / 1920 * 0.335);
    pointer-events: auto;
  }

  .art-slice--bot .art-img {
    display: block;
    width: 100%;
    height: auto;
    position: absolute;
    left: 0;
    bottom: 0;
    object-fit: fill;
  }

  /* хит-зоны категорий относительно нижнего куска */
  #categories .hit-cat {
    top: 13.5% !important;
    height: 56% !important;
  }

  .countdown-slot {
    position: relative;
    left: auto;
    top: auto;
    transform: none;
    width: calc(100% - 1.5rem);
    max-width: 26rem;
    height: auto;
    margin: 0.75rem auto;
    z-index: 5;
    overflow: visible;
    pointer-events: none;
    order: 0;
  }

  /* таймер между верхом и низом баннера */
  .art-split .countdown-slot {
    margin: 0.85rem auto;
  }

  .countdown {
    gap: 0.55rem;
    padding: 1rem 0.9rem 1.05rem;
    border-radius: 1.15rem;
    background:
      linear-gradient(165deg, rgba(86, 22, 128, 0.94) 0%, rgba(26, 5, 40, 0.97) 55%, rgba(14, 1, 24, 0.98) 100%);
    border: 1px solid rgba(255, 212, 0, 0.5);
    box-shadow:
      0 0 0 1px rgba(180, 60, 220, 0.2) inset,
      0 14px 36px rgba(0, 0, 0, 0.45),
      0 0 28px rgba(168, 50, 220, 0.22);
    backdrop-filter: blur(12px);
  }

  .cd-title {
    font-size: 0.82rem;
    white-space: normal;
  }

  .cd-row {
    gap: 0.35rem;
    width: 100%;
    justify-content: space-between;
  }

  .cd-unit {
    flex: 1 1 0;
  }

  .cd-num {
    font-size: clamp(1.45rem, 8vw, 1.85rem);
    filter: none;
  }

  .cd-sep {
    font-size: clamp(1.2rem, 6.5vw, 1.55rem);
    padding-top: 0.1em;
  }

  .cd-lbl {
    margin-top: 0.4em;
    font-size: 0.58rem;
    letter-spacing: 0.04em;
  }

  .cd-note {
    font-size: 0.72rem;
    white-space: normal;
    opacity: 0.9;
  }

  #categories .hit-cat {
    min-width: 11%;
    min-height: 22%;
  }

  .hit {
    border-width: 1px;
  }

  .hit-cat:hover {
    border-color: transparent;
    box-shadow: none;
    background: transparent;
  }

  .hit-cat:active,
  .hit-cat.is-active {
    border-color: #F5C518;
    box-shadow: 0 0 0 2px rgba(245, 197, 24, 0.45);
    background: rgba(245, 197, 24, 0.08);
  }

  .auth-bar {
    top: max(10px, env(safe-area-inset-top, 0px));
    right: max(10px, env(safe-area-inset-right, 0px));
    gap: 6px;
  }

  .login-btn {
    font-size: 11px;
    padding: 8px 12px;
  }

  .toast {
    bottom: max(1rem, env(safe-area-inset-bottom));
    font-size: 0.88rem;
    padding: 0.7rem 1.1rem;
  }

  /* Попап — bottom sheet на мобиле */
  .cta-modal {
    align-items: flex-end;
    padding:
      max(0.5rem, env(safe-area-inset-top))
      0
      0
      0;
  }

  .cta-modal__dialog {
    width: 100%;
    transform: translateY(100%);
  }

  .cta-modal.is-open .cta-modal__dialog {
    transform: translateY(0);
  }

  .cta-modal__card {
    border-radius: 1.35rem 1.35rem 0 0;
    padding: 1.35rem 1.2rem calc(1.25rem + env(safe-area-inset-bottom));
    gap: 0.55rem;
    border-bottom: 0;
  }

  .cta-modal__card::after {
    left: 50%;
    right: auto;
    top: 0.55rem;
    width: 2.5rem;
    height: 0.28rem;
    transform: translateX(-50%);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.28);
  }

  .cta-modal__close {
    top: 0.85rem;
    right: 0.85rem;
    width: 2.25rem;
    height: 2.25rem;
  }

  .cta-modal__eyebrow {
    margin-top: 0.35rem;
    font-size: 0.68rem;
  }

  .cta-modal__title {
    font-size: 1.35rem;
  }

  .cta-modal__sub {
    font-size: 0.9rem;
    max-width: none;
  }

  .cta-modal__btn {
    min-height: 3rem;
    font-size: 1rem;
    margin-top: 0.45rem;
  }
}

/* Узкие телефоны */
@media (max-width: 400px) {
  .countdown-slot {
    width: calc(100% - 1rem);
  }

  .art-slice--top {
    height: calc(100vw * 1003 / 1920 * 0.46);
  }

  .art-slice--bot {
    height: calc(100vw * 1003 / 1920 * 0.34);
  }

  .cd-num {
    font-size: 1.35rem;
  }

  .cd-sep {
    font-size: 1.15rem;
  }

  .cd-lbl {
    font-size: 0.52rem;
    letter-spacing: 0.02em;
  }

  .login-btn {
    font-size: 10px;
    padding: 7px 10px;
  }
}

/* Короткий экран в портрете */
@media (max-width: 767px) and (max-height: 700px) {
  .art-slice--top {
    height: calc(100vw * 1003 / 1920 * 0.42);
  }

  .art-slice--bot {
    height: calc(100vw * 1003 / 1920 * 0.3);
  }

  .countdown {
    padding: 0.85rem 0.8rem 0.9rem;
    gap: 0.4rem;
  }

  .countdown-slot {
    margin: 0.55rem auto;
  }

  .cd-num {
    font-size: 1.3rem;
  }
}

/* Ландшафт на телефоне — полный баннер с рамкой */
@media (max-width: 900px) and (orientation: landscape) and (max-height: 500px) {
  .art {
    display: block;
    aspect-ratio: 1920 / 1003;
    width: auto;
    height: 100dvh;
    max-height: 100dvh;
  }

  .art-frame {
    position: absolute;
    inset: 0;
    aspect-ratio: unset;
  }

  .art-img--full {
    display: block;
  }

  .art-split {
    position: absolute;
    inset: 0;
    display: block;
    pointer-events: none;
  }

  .art-slice--top {
    display: none;
  }

  .art-slice--bot {
    position: absolute;
    inset: 0;
    height: auto;
    overflow: visible;
  }

  .art-slice--bot .art-img {
    display: none;
  }

  #categories .hit-cat {
    top: 71% !important;
    height: 19% !important;
  }

  .countdown-slot {
    position: absolute;
    left: 52.1%;
    top: 56%;
    transform: translate(-50%, -50%);
    width: 36%;
    height: 32%;
    margin: 0;
    max-width: none;
  }

  .countdown {
    padding: 2% 3%;
    gap: 0.15rem;
    border-radius: 0;
    background: transparent;
    border: 0;
    box-shadow: none;
    backdrop-filter: none;
  }

  .cta-modal {
    align-items: center;
    padding: 0.75rem;
  }

  .cta-modal__dialog {
    width: min(100%, 24rem);
    transform: translateY(12px) scale(0.97);
  }

  .cta-modal.is-open .cta-modal__dialog {
    transform: translateY(0) scale(1);
  }

  .cta-modal__card {
    border-radius: 1.2rem;
    padding: 1.2rem 1.1rem 1.1rem;
  }
}

@supports not (width: 1cqw) {
  .cd-title { font-size: clamp(0.55rem, 1.15vw, 1rem); }
  .cd-note { font-size: clamp(0.45rem, 0.9vw, 0.78rem); }
  .cd-num { font-size: clamp(0.85rem, 2.85vw, 2.55rem); }
  .cd-lbl { font-size: clamp(0.35rem, 0.78vw, 0.68rem); }
  .cd-sep { font-size: clamp(0.75rem, 2.5vw, 2.2rem); }
}
  </style>
</head>
<body>
  <div class="auth-bar">
    <?php if (\App\Core\Auth::check()): ?>
      <form method="post" action="<?= htmlspecialchars($logoutUrl) ?>">
        <?= \App\Core\Csrf::field() ?>
        <button type="submit" class="login-btn">Выйти</button>
      </form>
    <?php else: ?>
      <a class="login-btn" href="<?= htmlspecialchars($loginUrl) ?>">Вход</a>
      <button type="button" class="login-btn login-btn--accent" id="cta-open" aria-haspopup="dialog">
        Регистрация
      </button>
    <?php endif; ?>
  </div>

  <main class="stage">
    <section class="hero" aria-label="Zakopeyki.kz">
      <div class="art">
        <div class="art-frame">
          <img
            src="<?= htmlspecialchars($heroUrl) ?>"
            width="1920"
            height="1003"
            alt="Zakopeyki.kz — Антикризисная платформа № 1 в Казахстане"
            class="art-img art-img--full"
            draggable="false"
            decoding="async"
            fetchpriority="high"
          />

          <div class="art-split">
            <div class="art-slice art-slice--top" aria-hidden="true">
              <img
                src="<?= htmlspecialchars($heroUrl) ?>"
                width="1920"
                height="1003"
                alt=""
                class="art-img"
                draggable="false"
                decoding="async"
              />
            </div>

            <div class="countdown-slot" aria-live="polite">
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
                <p class="cd-note">30 сентября откроется сайт</p>
              </div>
            </div>

            <div class="art-slice art-slice--bot">
              <img
                src="<?= htmlspecialchars($heroUrl) ?>"
                width="1920"
                height="1003"
                alt=""
                class="art-img"
                draggable="false"
                decoding="async"
                aria-hidden="true"
              />
              <nav id="categories" aria-label="Категории">
                <button type="button" class="hit hit-cat" style="left:9.8%; top:68.5%; width:12.4%; height:23.5%" data-label="Товары new" aria-label="Товары new"></button>
                <button type="button" class="hit hit-cat" style="left:23.6%; top:68.5%; width:12.4%; height:23.5%" data-label="Товары Б/у" aria-label="Товары Б/у"></button>
                <button type="button" class="hit hit-cat" style="left:37.4%; top:68.5%; width:12.4%; height:23.5%" data-label="Аукционы" aria-label="Аукционы"></button>
                <button type="button" class="hit hit-cat" style="left:51.2%; top:68.5%; width:12.4%; height:23.5%" data-label="Услуги" aria-label="Услуги"></button>
                <button type="button" class="hit hit-cat" style="left:65.0%; top:68.5%; width:12.4%; height:23.5%" data-label="Обмен" aria-label="Обмен"></button>
                <button type="button" class="hit hit-cat" style="left:78.8%; top:68.5%; width:12.4%; height:23.5%" data-label="Даром" aria-label="Даром"></button>
              </nav>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>

  <div id="toast" class="toast" role="status" aria-live="polite" hidden></div>

  <?php if (!\App\Core\Auth::check()): ?>
  <div
    id="cta-modal"
    class="cta-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="cta-modal-title"
    hidden
  >
    <button type="button" class="cta-modal__backdrop" data-cta-close aria-label="Закрыть"></button>
    <div class="cta-modal__dialog">
      <div class="cta-modal__card">
        <button type="button" class="cta-modal__close" data-cta-close aria-label="Закрыть">×</button>
        <p class="cta-modal__eyebrow">Ранний доступ</p>
        <p class="cta-modal__title" id="cta-modal-title">Будьте среди первых</p>
        <p class="cta-modal__sub">
          30 сентября открываем сайт. Зарегистрируйтесь заранее — и встретьте запуск во всеоружии.
        </p>
        <a class="cta-modal__btn" href="<?= htmlspecialchars($registerUrl) ?>">
          Создать аккаунт
          <span class="cta-modal__arrow" aria-hidden="true">→</span>
        </a>
        <p class="cta-modal__hint">Бесплатно · займёт меньше минуты</p>
      </div>
    </div>
  </div>
  <?php endif; ?>

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

function initCtaModal() {
  const modal = document.getElementById('cta-modal');
  if (!modal) return;

  const storageKey = 'stub_cta_dismissed';
  let lastFocus = null;
  const isTouch = window.matchMedia('(hover: none), (pointer: coarse)').matches;

  function openModal() {
    lastFocus = document.activeElement;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
      modal.classList.add('is-open');
      if (!isTouch) {
        modal.querySelector('.cta-modal__btn')?.focus({ preventScroll: true });
      }
    });
  }

  function closeModal(persist) {
    modal.classList.remove('is-open');
    document.body.style.overflow = '';
    if (persist) {
      try { sessionStorage.setItem(storageKey, '1'); } catch (_) {}
    }
    setTimeout(() => {
      if (!modal.classList.contains('is-open')) modal.hidden = true;
    }, 280);
    if (!isTouch && lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus({ preventScroll: true });
    }
  }

  modal.querySelectorAll('[data-cta-close]').forEach((el) => {
    el.addEventListener('click', () => closeModal(true));
  });

  document.getElementById('cta-open')?.addEventListener('click', (e) => {
    e.preventDefault();
    openModal();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) {
      closeModal(true);
    }
  });

  let dismissed = false;
  try { dismissed = sessionStorage.getItem(storageKey) === '1'; } catch (_) {}

  if (!dismissed) {
    setTimeout(openModal, 1600);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  if (new URLSearchParams(location.search).get('debug') === '1') {
    document.body.classList.add('debug');
  }

  initCountdown();
  initCtaModal();

  const stubFlash = <?= json_encode($stubFlash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  if (stubFlash) {
    showToast(stubFlash);
  }

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
