/* Zakopeyki AI Virtual Try-On — клиент API /api/v1/try-on (docs/tryon/02-api-contracts.md). */
(function () {
    'use strict';

    var modal = document.getElementById('tryon-modal');
    if (!modal) return;

    var cfg = {};
    try { cfg = JSON.parse(modal.getAttribute('data-tryon-config') || '{}'); } catch (e) { return; }
    var t = cfg.i18n || {};
    var api = String(cfg.api || '').replace(/\/$/, '');
    var productId = cfg.productId;

    var MAX_SIDE = 2048;
    var state = {
        caps: null,
        garmentIndex: 0,
        photoBlob: null,
        previewUrl: null,
        idemKey: null,
        job: null,
        pollTimer: null,
        pollFailures: 0,
        open: false,
        lastFocus: null
    };

    function $(sel) { return modal.querySelector(sel); }
    function $$(sel) { return Array.prototype.slice.call(modal.querySelectorAll(sel)); }

    function csrf() {
        if (window.__csrfToken) return window.__csrfToken;
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function newKey() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID().replace(/-/g, '');
        var a = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(a);
        return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }

    function request(method, path, body) {
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (method !== 'GET') headers['X-CSRF-Token'] = csrf();
        if (body && body.idem) headers['Idempotency-Key'] = body.idem;
        return fetch(api + path, {
            method: method,
            credentials: 'same-origin',
            headers: headers,
            body: body ? body.form : undefined
        }).then(function (res) {
            return res.json().catch(function () { return { success: false, data: { code: 'bad_response' } }; })
                .then(function (json) { json._status = res.status; return json; });
        });
    }

    function show(step) {
        $$('[data-tryon-step]').forEach(function (el) { el.classList.toggle('hidden', el.getAttribute('data-tryon-step') !== step); });
        $$('[data-tryon-actions]').forEach(function (el) { el.classList.toggle('hidden', el.getAttribute('data-tryon-actions') !== step); });
    }

    function setError(text) {
        var el = $('[data-tryon-error]');
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('hidden', !text);
    }

    function errorText(json) {
        return (json && json.error) || t.generic || 'Error';
    }

    // ---------- открытие / закрытие ----------
    function openModal() {
        state.lastFocus = document.activeElement;
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('overflow-hidden');
        state.open = true;
        show('loading');
        loadCapabilities();
        var close = $('[data-tryon-close]');
        if (close) close.focus();
    }

    function closeModal() {
        state.open = false;
        stopPolling();
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('overflow-hidden');
        if (state.lastFocus && state.lastFocus.focus) state.lastFocus.focus();
    }

    document.querySelectorAll('[data-tryon-open]').forEach(function (btn) {
        btn.addEventListener('click', function (e) { e.preventDefault(); openModal(); });
    });
    $$('[data-tryon-close]').forEach(function (b) { b.addEventListener('click', closeModal); });
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (state.open && e.key === 'Escape') closeModal(); });

    // ---------- возможности и восстановление последнего задания ----------
    function loadCapabilities() {
        request('GET', '/capabilities?product_id=' + encodeURIComponent(productId)).then(function (json) {
            if (!json.success || !json.data || !json.data.product || !json.data.product.supported) {
                failed(errorText(json.success ? { error: t.job_failed_product } : json));
                return;
            }
            state.caps = json.data;
            renderGarments();
            renderLimits();
            return request('GET', '/jobs?limit=1&product_id=' + encodeURIComponent(productId)).then(function (list) {
                var last = list.success && list.data && list.data.jobs && list.data.jobs[0];
                if (last && (last.status === 'queued' || last.status === 'processing')) {
                    state.job = last;
                    showProgress(last);
                    startPolling();
                } else if (last && last.status === 'succeeded' && last.result_available) {
                    showResult(last);
                } else {
                    resetSetup();
                }
            });
        }).catch(function () { failed(t.network); });
    }

    function renderGarments() {
        var wrap = $('[data-tryon-garments]');
        var outer = $('[data-tryon-garments-wrap]');
        var imgs = state.caps.product.garment_images || [];
        wrap.innerHTML = '';
        outer.classList.toggle('hidden', imgs.length < 2);
        state.garmentIndex = imgs.length ? imgs[0].index : 0;
        imgs.forEach(function (g, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('role', 'radio');
            b.setAttribute('aria-checked', i === 0 ? 'true' : 'false');
            b.setAttribute('aria-label', String(t.garment || '').replace(':n', String(i + 1)));
            b.className = 'shrink-0 w-16 h-20 rounded-xl overflow-hidden border-2 transition ' + (i === 0 ? 'border-brand-600' : 'border-transparent opacity-70');
            var im = document.createElement('img');
            im.src = g.url; im.alt = ''; im.loading = 'lazy';
            im.className = 'w-full h-full object-cover';
            b.appendChild(im);
            b.addEventListener('click', function () {
                state.garmentIndex = g.index;
                Array.prototype.forEach.call(wrap.children, function (c, j) {
                    var on = j === i;
                    c.setAttribute('aria-checked', on ? 'true' : 'false');
                    c.classList.toggle('border-brand-600', on);
                    c.classList.toggle('border-transparent', !on);
                    c.classList.toggle('opacity-70', !on);
                });
                state.idemKey = null;
            });
            wrap.appendChild(b);
        });
    }

    function renderLimits() {
        var el = $('[data-tryon-limits]');
        var l = state.caps && state.caps.limits;
        if (!el) return;
        el.textContent = l ? String(t.left_today || '').replace(':n', String(l.daily_left)) : '';
    }

    // ---------- выбор фото ----------
    function resetSetup() {
        clearPhoto();
        $('[data-tryon-consent]').checked = false;
        setError('');
        updateSubmit();
        show('setup');
    }

    function clearPhoto() {
        if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
        state.previewUrl = null;
        state.photoBlob = null;
        state.idemKey = null;
        $('[data-tryon-preview-wrap]').classList.add('hidden');
        $('[data-tryon-pickers]').classList.remove('hidden');
        $$('[data-tryon-file]').forEach(function (i) { i.value = ''; });
    }

    function updateSubmit() {
        $('[data-tryon-submit]').disabled = !(state.photoBlob && $('[data-tryon-consent]').checked);
    }

    function loadBitmap(file) {
        if (window.createImageBitmap) {
            return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () { return createImageBitmap(file); });
        }
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.onload = function () { resolve(img); };
            img.onerror = reject;
            img.src = URL.createObjectURL(file);
        });
    }

    // Сжатие на устройстве: меньше трафика на медленном интернете, EXIF (в т.ч. GPS) не уходит на сервер.
    function prepare(file) {
        var caps = state.caps.upload || {};
        if (file.size > (caps.max_bytes || 10485760) * 3) return Promise.reject(t.photo_big);
        if (!/^image\//.test(file.type || 'image/')) return Promise.reject(t.photo_format);
        return loadBitmap(file).then(function (bmp) {
            var w = bmp.width, h = bmp.height;
            if (Math.min(w, h) < (caps.min_side_px || 512)) throw t.photo_small;
            var k = Math.min(1, MAX_SIDE / Math.max(w, h));
            var c = document.createElement('canvas');
            c.width = Math.round(w * k); c.height = Math.round(h * k);
            var ctx = c.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
            ctx.drawImage(bmp, 0, 0, c.width, c.height);
            if (bmp.close) bmp.close();
            return new Promise(function (resolve, reject) {
                c.toBlob(function (b) { b ? resolve(b) : reject(t.photo_format); }, 'image/jpeg', 0.9);
            });
        }, function (err) { throw typeof err === 'string' ? err : t.photo_format; });
    }

    $$('[data-tryon-file]').forEach(function (input) {
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f) return;
            setError('');
            prepare(f).then(function (blob) {
                if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
                state.photoBlob = blob;
                state.idemKey = null;
                state.previewUrl = URL.createObjectURL(blob);
                $('[data-tryon-preview]').src = state.previewUrl;
                $('[data-tryon-preview-wrap]').classList.remove('hidden');
                $('[data-tryon-pickers]').classList.add('hidden');
                updateSubmit();
            }).catch(function (msg) {
                clearPhoto();
                setError(typeof msg === 'string' ? msg : t.photo_format);
                updateSubmit();
            });
        });
    });
    $('[data-tryon-photo-reset]').addEventListener('click', function () { clearPhoto(); updateSubmit(); });
    $('[data-tryon-consent]').addEventListener('change', updateSubmit);

    // ---------- запуск ----------
    $('[data-tryon-submit]').addEventListener('click', function () {
        var btn = this;
        if (!state.photoBlob || !$('[data-tryon-consent]').checked) return;
        btn.disabled = true;
        setError('');
        // Ключ сохраняется до успешного ответа: повтор после обрыва сети не создаст второе задание.
        state.idemKey = state.idemKey || newKey();
        var fd = new FormData();
        fd.append('photo', state.photoBlob, 'photo.jpg');
        fd.append('product_id', String(productId));
        fd.append('garment_image_index', String(state.garmentIndex));
        fd.append('consent', '1');
        fd.append('consent_version', state.caps.consent.version);
        request('POST', '/jobs', { form: fd, idem: state.idemKey }).then(function (json) {
            if (!json.success) {
                if (json.data && json.data.code === 'consent_outdated') state.caps.consent.version = json.data.consent_version || state.caps.consent.version;
                setError(errorText(json));
                updateSubmit();
                return;
            }
            state.idemKey = null;
            state.job = json.data.job;
            if (state.job.status === 'succeeded') {
                showResult(state.job);
            } else {
                showProgress(state.job);
                startPolling();
            }
        }).catch(function () {
            setError(t.network);
            updateSubmit();
        });
    });

    // ---------- ожидание ----------
    function showProgress(job) {
        $('[data-tryon-status]').textContent = job.status === 'processing' ? t.status_processing : t.status_queued;
        show('progress');
    }

    function stopPolling() {
        if (state.pollTimer) clearTimeout(state.pollTimer);
        state.pollTimer = null;
    }

    function startPolling(delay) {
        stopPolling();
        if (!state.open || !state.job) return;
        state.pollTimer = setTimeout(poll, delay || (state.job.poll_after_ms || 3000));
    }

    function poll() {
        if (!state.open || !state.job) return;
        request('GET', '/jobs/' + encodeURIComponent(state.job.id)).then(function (json) {
            state.pollFailures = 0;
            if (!json.success) { failed(errorText(json)); return; }
            var job = state.job = json.data.job;
            if (job.status === 'queued' || job.status === 'processing') {
                showProgress(job);
                startPolling();
            } else if (job.status === 'succeeded') {
                showResult(job);
            } else if (job.status === 'failed') {
                failed(job.error_code === 'product_not_supported' ? t.job_failed_product : t.job_failed);
            } else {
                resetSetup();
            }
        }).catch(function () {
            // Медленная/пропавшая сеть: продолжаем с увеличивающейся паузой.
            state.pollFailures++;
            startPolling(Math.min(15000, 3000 * Math.pow(2, state.pollFailures)));
        });
    }

    // ---------- результат ----------
    function showResult(job) {
        var stamp = '?v=' + encodeURIComponent(job.id);
        $('[data-tryon-result]').src = api + '/jobs/' + encodeURIComponent(job.id) + '/result' + stamp;
        $('[data-tryon-before]').src = api + '/jobs/' + encodeURIComponent(job.id) + '/photo' + stamp;
        $('[data-tryon-test-badge]').classList.toggle('hidden', !job.is_test);
        var exp = $('[data-tryon-expires]');
        if (job.expires_at) {
            var d = new Date(job.expires_at);
            exp.textContent = String(t.expires || '').replace(':time', d.toLocaleString([], { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }));
        } else {
            exp.textContent = '';
        }
        setSlider(50);
        show('result');
    }

    function setSlider(v) {
        $('[data-tryon-compare]').style.setProperty('--pos', v + '%');
        $('[data-tryon-slider]').value = String(v);
    }
    $('[data-tryon-slider]').addEventListener('input', function () { setSlider(this.value); });

    function failed(text) {
        stopPolling();
        $('[data-tryon-failed-text]').textContent = text || t.generic;
        show('failed');
    }

    $$('[data-tryon-again]').forEach(function (b) {
        b.addEventListener('click', function () {
            state.job = null;
            if (!state.caps) { show('loading'); loadCapabilities(); return; }
            request('GET', '/capabilities?product_id=' + encodeURIComponent(productId)).then(function (json) {
                if (json.success) { state.caps = json.data; renderLimits(); }
            }).catch(function () {});
            resetSetup();
        });
    });

    $$('[data-tryon-delete]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!state.job) { resetSetup(); return; }
            if (!window.confirm(t.delete_confirm)) return;
            var id = state.job.id;
            stopPolling();
            request('DELETE', '/jobs/' + encodeURIComponent(id)).then(function (json) {
                if (!json.success && json._status !== 404) { failed(errorText(json)); return; }
                state.job = null;
                $('[data-tryon-result]').removeAttribute('src');
                $('[data-tryon-before]').removeAttribute('src');
                resetSetup();
            }).catch(function () { failed(t.network); });
        });
    });
})();
