/* Изолированный браузерный стенд: замер загрузки, скорости и памяти vosk-browser и openWakeWord (onnxruntime-web).
 * Аудио — синтетические тестовые потоки (никаких записей пользователей), обработка локально в браузере. */
window.__bench = { vosk: {}, oww: {} };

const VOSK_MODELS = { ru: 'vosk-model-small-ru-0.22', kk: 'vosk-model-small-kz-0.42', en: 'vosk-model-small-en-us-0.15' };
const GRAMMAR = {
    ru: ['зак', 'эй зак', 'хей зак', '[unk]'],
    kk: ['зак', 'эй зак', 'әй зак', 'хей зак', '[unk]'],
    en: ['zack', 'zak', 'hey zack', 'hey zak', '[unk]'],
};
const WAKE = new Set(['зак', 'zack', 'zak']);

function log(msg) { document.getElementById('log').textContent += msg + '\n'; console.log(msg); }

async function memoryMB() {
    if (window.crossOriginIsolated && performance.measureUserAgentSpecificMemory) {
        try {
            const m = await performance.measureUserAgentSpecificMemory();
            return Math.round(m.bytes / 1e6);
        } catch (e) { /* ignore */ }
    }
    return performance.memory ? Math.round(performance.memory.usedJSHeapSize / 1e6) : null;
}

async function loadStream(name) {
    const buf = await (await fetch('streams/' + name + '.wav')).arrayBuffer();
    const dv = new DataView(buf);
    const n = (buf.byteLength - 44) / 2;
    const f = new Float32Array(n);
    for (let i = 0; i < n; i++) f[i] = dv.getInt16(44 + i * 2, true) / 32768;
    return f;
}

/* ---------------- Vosk (vosk-browser 0.0.8, WASM в Web Worker) ---------------- */
async function runVosk(lang, cond = 'clean') {
    const mem0 = await memoryMB();
    const t0 = performance.now();
    const model = await Vosk.createModel(VOSK_MODELS[lang] + '.tar.gz', -1);
    const loadMs = performance.now() - t0;
    const memLoaded = await memoryMB();

    const rec = new model.KaldiRecognizer(16000, JSON.stringify(GRAMMAR[lang]));
    rec.setWords(true);
    const detections = [];
    let partialHits = 0;
    let lastPartialCount = 0;
    let finals = 0;
    let resolveDone;
    const done = new Promise(r => { resolveDone = r; });
    rec.on('result', (msg) => {
        finals++;
        const words = (msg.result.result || []);
        words.forEach((w) => { if (WAKE.has(w.word)) detections.push({ t: w.end, conf: w.conf }); });
        lastPartialCount = 0;
        if (msg.result.text === '__end__' || rec.__ending) resolveDone();
    });
    rec.on('partialresult', (msg) => {
        const c = (msg.result.partial || '').split(' ').filter(w => WAKE.has(w)).length;
        if (c > lastPartialCount) partialHits += c - lastPartialCount;
        lastPartialCount = c;
    });

    const audio = await loadStream(lang + '_' + cond);
    const chunk = 1600;
    const tFeed = performance.now();
    for (let off = 0; off < audio.length; off += chunk) {
        rec.acceptWaveformFloat(audio.subarray(off, off + chunk), 16000);
        if ((off / chunk) % 50 === 0) await new Promise(r => setTimeout(r, 0));
    }
    rec.__ending = true;
    rec.retrieveFinalResult();
    await Promise.race([done, new Promise(r => setTimeout(r, 600000))]);
    const procMs = performance.now() - tFeed;
    const memPeak = await memoryMB();
    const res = {
        model: VOSK_MODELS[lang], load_ms: Math.round(loadMs), audio_s: +(audio.length / 16000).toFixed(1),
        process_ms: Math.round(procMs), rtf: +(procMs / (audio.length / 16) ).toFixed(3),
        detections_final: detections.length, detections_conf90: detections.filter(d => d.conf >= 0.9).length,
        partial_hits: partialHits, mem_before_mb: mem0, mem_after_load_mb: memLoaded, mem_after_run_mb: memPeak,
        crossOriginIsolated: window.crossOriginIsolated,
    };
    rec.remove();
    model.terminate();
    window.__bench.vosk[lang + '_' + cond] = res;
    log('vosk ' + lang + ': ' + JSON.stringify(res));
    return res;
}

/* ---------------- openWakeWord (onnxruntime-web, порт потокового конвейера) ---------------- */
function mlpPredict(head, x) {
    let a = x;
    for (let l = 0; l < head.coefs.length; l++) {
        const W = head.coefs[l], b = head.intercepts[l];
        const out = new Float32Array(b.length);
        for (let j = 0; j < b.length; j++) {
            let s = b[j];
            for (let i = 0; i < a.length; i++) s += a[i] * W[i][j];
            out[j] = s;
        }
        a = l < head.coefs.length - 1 ? out.map(v => Math.max(0, v)) : out.map(v => 1 / (1 + Math.exp(-v)));
    }
    return a[0];
}

async function runOww(lang, cond = 'clean', threshold = 0.9, threads = 1) {
    ort.env.wasm.numThreads = threads;
    ort.env.wasm.wasmPaths = 'node_modules/onnxruntime-web/dist/';
    const mem0 = await memoryMB();
    const t0 = performance.now();
    const mel = await ort.InferenceSession.create('melspectrogram.onnx', { executionProviders: ['wasm'] });
    const emb = await ort.InferenceSession.create('embedding_model.onnx', { executionProviders: ['wasm'] });
    const heads = await (await fetch('results/oww_heads.json')).json();
    const loadMs = performance.now() - t0;
    const memLoaded = await memoryMB();

    const audio = await loadStream(lang + '_' + cond);
    const int16 = new Float32Array(audio.length);
    for (let i = 0; i < audio.length; i++) int16[i] = Math.round(audio[i] * 32767);

    let melBuf = Array.from({ length: 76 }, () => new Float32Array(32).fill(1));
    let featBuf = Array.from({ length: 16 }, () => new Float32Array(96));
    const scores = { zak: [], eyzak: [], heyzak: [] };
    const frameMs = [];
    const tRun = performance.now();
    for (let off = 1280; off + 1280 <= audio.length; off += 1280) {
        const f0 = performance.now();
        const start = Math.max(0, off + 1280 - 1280 - 480);
        const slice = int16.subarray(start, off + 1280);
        const mOut = await mel.run({ input: new ort.Tensor('float32', slice, [1, slice.length]) });
        const spec = mOut[mel.outputNames[0]];
        const nFrames = spec.dims[spec.dims.length - 2];
        for (let r = 0; r < nFrames; r++) {
            const row = new Float32Array(32);
            for (let c = 0; c < 32; c++) row[c] = spec.data[r * 32 + c] / 10 + 2;
            melBuf.push(row);
        }
        if (melBuf.length > 970) melBuf = melBuf.slice(-970);
        const win = new Float32Array(76 * 32);
        melBuf.slice(-76).forEach((row, i) => win.set(row, i * 32));
        const eOut = await emb.run({ input_1: new ort.Tensor('float32', win, [1, 76, 32, 1]) });
        featBuf.push(Float32Array.from(eOut[emb.outputNames[0]].data));
        if (featBuf.length > 120) featBuf = featBuf.slice(-120);
        const x = new Float32Array(16 * 96);
        featBuf.slice(-16).forEach((row, i) => x.set(row, i * 96));
        const t = (off + 1280) / 16000;
        for (const m of Object.keys(scores)) scores[m].push([t, mlpPredict(heads[m], x)]);
        frameMs.push(performance.now() - f0);
        if (frameMs.length % 200 === 0) await new Promise(r => setTimeout(r, 0));
    }
    const procMs = performance.now() - tRun;
    const memPeak = await memoryMB();
    const det = (series) => { let n = 0, above = false; series.forEach(([, s]) => { if (s >= threshold && !above) n++; above = s >= threshold; }); return n; };
    frameMs.sort((a, b) => a - b);
    const res = {
        threads, load_ms: Math.round(loadMs), audio_s: +(audio.length / 16000).toFixed(1), process_ms: Math.round(procMs),
        rtf: +(procMs / (audio.length / 16)).toFixed(3), frame_ms_median: +frameMs[frameMs.length >> 1].toFixed(2),
        frame_ms_p95: +frameMs[Math.floor(frameMs.length * 0.95)].toFixed(2),
        detections: { zak: det(scores.zak), eyzak: det(scores.eyzak), heyzak: det(scores.heyzak) }, threshold,
        mem_before_mb: mem0, mem_after_load_mb: memLoaded, mem_after_run_mb: memPeak,
        model_bytes: 1087958 + 1326578, crossOriginIsolated: window.crossOriginIsolated,
    };
    window.__bench.oww[lang + '_' + cond] = res;
    window.__bench.owwScores = window.__bench.owwScores || {};
    window.__bench.owwScores[lang + '_' + cond] = scores;
    log('oww ' + lang + ': ' + JSON.stringify(res));
    return res;
}
