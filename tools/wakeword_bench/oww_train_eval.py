"""
openWakeWord: обучение «головы» для каждой фразы (zak / eyzak / heyzak) поверх штатных
признаков openWakeWord (melspectrogram.onnx + embedding_model.onnx) и потоковое испытание.

Упрощённый вариант официального рецепта: вместо тысяч клипов Piper + ~2000 ч негативов
(датасет с лицензией CC BY-NC-SA) используются только наши синтетические клипы train-дикторов.
Испытание — на потоках test-дикторов, которых модель не видела.

Запуск: ~/.local/venvs/oww/bin/python tools/wakeword_bench/oww_train_eval.py
"""
from __future__ import annotations

import json
import random
import resource
import time
import wave

import numpy as np
from openwakeword.utils import AudioFeatures
from sklearn.neural_network import MLPClassifier

from metrics import MODES, RESULTS, load_manifest, score

SR = 16000
WIN = int(2.0 * SR)  # окно клипа 2 с, фраза в конце
FRAMES = 16          # 16 эмбеддингов по 80 мс ≈ 1.28 с (как у штатных моделей openWakeWord)
THRESHOLDS = [0.3, 0.5, 0.7, 0.8, 0.9, 0.95, 0.98]
rng = random.Random(7)
np_rng = np.random.default_rng(7)


def read(path: str) -> np.ndarray:
    with wave.open(path, "rb") as wf:
        return np.frombuffer(wf.readframes(wf.getnframes()), dtype=np.int16).astype(np.float32) / 32768


def place(clip: np.ndarray, bg: np.ndarray | None, end_at: float, gain: float, snr: float | None) -> np.ndarray:
    out = np.zeros(WIN, dtype=np.float32)
    end = int(end_at * SR)
    clip = clip[-end:] if len(clip) > end else clip
    out[end - len(clip): end] = clip * gain
    if bg is not None and snr is not None:
        s = rng.randrange(0, max(1, len(bg) - WIN))
        noise = bg[s: s + WIN]
        if len(noise) < WIN:
            noise = np.pad(noise, (0, WIN - len(noise)))
        sig_rms = np.sqrt(np.mean((clip * gain) ** 2)) + 1e-9
        k = sig_rms / ((np.sqrt(np.mean(noise ** 2)) + 1e-9) * 10 ** (snr / 20))
        out += k * noise
    return np.clip(out, -1, 1)


def to_int16(x: np.ndarray) -> np.ndarray:
    return (x * 32767).astype(np.int16)


def build_training(man: dict, feats: AudioFeatures):
    pos = {m: [] for m in MODES}
    neg = []
    for key, recs in man["clips"].items():
        if not key.endswith("_train"):
            continue
        for r in recs:
            a = read(r["path"])
            (neg if r["label"] == "neg" else pos[r["label"]]).append(a)
    # фоны для аугментации: речь-негативы, шум, синтетическая музыка из train-клипов не берём — шум генерируем
    babble = np.concatenate(neg[: 200]) if neg else np.zeros(SR)
    pink = np_rng.standard_normal(SR * 30).astype(np.float32) * 0.1
    backgrounds = [babble, pink]

    def augment(clips: list[np.ndarray], n_aug: int, end_range=(1.6, 1.95)) -> list[np.ndarray]:
        out = []
        for c in clips:
            for _ in range(n_aug):
                bg = rng.choice(backgrounds + [None])
                out.append(place(c, bg, rng.uniform(*end_range), rng.uniform(0.3, 1.6), rng.uniform(3, 20) if bg is not None else None))
        return out

    X_neg_audio = augment(neg, 4, (0.6, 1.95))
    # чистая тишина/шум
    for _ in range(300):
        X_neg_audio.append(place(np.zeros(10), rng.choice(backgrounds), 1.0, 1.0, rng.uniform(-5, 10)))
    data = {}
    neg_emb = feats.embed_clips(to_int16(np.stack(X_neg_audio)), batch_size=64)[:, -FRAMES:, :]
    for m in MODES:
        P = augment(pos[m], 8)
        # для eyzak/heyzak «голое» обращение и другие варианты — негативы (детектор должен быть специфичным)
        others = [c for mm in MODES if mm != m and m != "zak" for c in pos[mm]]
        N_extra = augment(others, 3) if others else []
        P_emb = feats.embed_clips(to_int16(np.stack(P)), batch_size=64)[:, -FRAMES:, :]
        if N_extra:
            NE = feats.embed_clips(to_int16(np.stack(N_extra)), batch_size=64)[:, -FRAMES:, :]
            N_emb = np.concatenate([neg_emb, NE])
        else:
            N_emb = neg_emb
        data[m] = (P_emb, N_emb)
        print(f"train {m}: pos={len(P_emb)} neg={len(N_emb)}", flush=True)
    return data


def train_heads(data) -> dict:
    heads = {}
    for m, (P, N) in data.items():
        X = np.concatenate([P, N]).reshape(len(P) + len(N), -1)
        y = np.concatenate([np.ones(len(P)), np.zeros(len(N))])
        clf = MLPClassifier(hidden_layer_sizes=(32,), alpha=1e-3, max_iter=300, early_stopping=True, random_state=0)
        # балансировка: дублируем позитивы
        reps = max(1, len(N) // max(1, len(P)))
        Xb = np.concatenate([X[: len(P)]] * reps + [X[len(P):]])
        yb = np.concatenate([np.ones(len(P) * reps), np.zeros(len(N))])
        t0 = time.perf_counter()
        clf.fit(Xb, yb)
        heads[m] = clf
        n_params = sum(w.size for w in clf.coefs_) + sum(b.size for b in clf.intercepts_)
        print(f"head {m}: fitted in {time.perf_counter() - t0:.1f}s, params={n_params}", flush=True)
    return heads


def stream_scores(path: str, heads: dict) -> tuple[dict, float, float]:
    x = to_int16(read(path))
    feats = AudioFeatures()
    scores = {m: [] for m in heads}
    cpu0 = time.process_time()
    for off in range(0, len(x) - 1280, 1280):
        feats._streaming_features(x[off: off + 1280])
        f = feats.get_features(FRAMES).reshape(1, -1)
        t = (off + 1280) / SR
        for m, clf in heads.items():
            scores[m].append((t, float(clf.predict_proba(f)[0, 1])))
    cpu = time.process_time() - cpu0
    return scores, cpu, len(x) / SR


def detections(series: list[tuple[float, float]], thr: float) -> list[float]:
    out, above = [], False
    for t, s in series:
        if s >= thr and not above:
            out.append(t)
        above = s >= thr
    return out


def export_heads(heads: dict) -> None:
    """Веса голов в JSON — для браузерного стенда (onnxruntime-web считает признаки, голова — в JS)."""
    payload = {}
    for m, clf in heads.items():
        payload[m] = {"activation": clf.activation, "coefs": [c.tolist() for c in clf.coefs_],
                      "intercepts": [b.tolist() for b in clf.intercepts_], "frames": FRAMES}
    (RESULTS / "oww_heads.json").write_text(json.dumps(payload))


def main() -> None:
    RESULTS.mkdir(parents=True, exist_ok=True)
    man = load_manifest()
    feats = AudioFeatures()
    t0 = time.perf_counter()
    data = build_training(man, feats)
    heads = train_heads(data)
    train_s = time.perf_counter() - t0
    export_heads(heads)
    out = {"engine": "openwakeword", "train_seconds": round(train_s, 1), "rows": []}
    for st in man["streams"]:
        sc, cpu, dur = stream_scores(st["path"], heads)
        for m in MODES:
            for thr in THRESHOLDS:
                r = score(m, st["events"], dur, detections(sc[m], thr))
                if r["targets"] == 0:
                    continue
                out["rows"].append({"lang": st["lang"], "cond": st["cond"], "mode": m, "variant": f"thr{thr}",
                                    "rtf": round(cpu / dur, 4), **r})
        print(f"oww {st['lang']} {st['cond']}: rtf={cpu / dur:.3f}", flush=True)
    out["peak_rss_mb"] = round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1e6, 1)
    (RESULTS / "oww.json").write_text(json.dumps(out, ensure_ascii=False, indent=1))
    print("saved", RESULTS / "oww.json")


if __name__ == "__main__":
    main()
