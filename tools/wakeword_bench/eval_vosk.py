"""
Испытание Vosk (тот же движок Kaldi, что внутри vosk-browser) как детектора обращения
через распознавание с ограниченной грамматикой. Потоковая подача по 100 мс, как в браузере.

Режимы детекции:
  partial    — срабатывание, как только фраза появилась в промежуточном результате (минимальная задержка);
  final_conf — только по финальному результату и уверенности слова «зак» ≥ 0.9.

Запуск: ~/.local/venvs/oww/bin/python tools/wakeword_bench/eval_vosk.py
"""
from __future__ import annotations

import json
import os
import resource
import time
import wave

import numpy as np
import vosk

from metrics import MODES, RESULTS, ROOT, load_manifest, score

vosk.SetLogLevel(-1)

MODELS = {
    "ru": "vosk-model-small-ru-0.22",
    "kk": "vosk-model-small-kz-0.42",
    "en": "vosk-model-small-en-us-0.15",
}
GRAMMAR = {
    "ru": ["зак", "эй зак", "хей зак", "[unk]"],
    "kk": ["зак", "эй зак", "әй зак", "хей зак", "[unk]"],
    "en": ["zack", "zak", "hey zack", "hey zak", "[unk]"],
}
WAKE_WORDS = {"зак", "zack", "zak"}
PREFIX = {"eyzak": {"эй", "әй"}, "heyzak": {"хей", "hey"}}
CHUNK = 1600  # 100 мс


def count_mode(words: list[str], mode: str) -> int:
    n = 0
    for i, w in enumerate(words):
        if w in WAKE_WORDS:
            if mode == "zak" or (i > 0 and words[i - 1] in PREFIX[mode]):
                n += 1
    return n


def run_stream(model: vosk.Model, lang: str, path: str) -> tuple[dict, dict, float, float]:
    rec = vosk.KaldiRecognizer(model, 16000, json.dumps(GRAMMAR[lang], ensure_ascii=False))
    rec.SetWords(True)
    with wave.open(path, "rb") as wf:
        audio = wf.readframes(wf.getnframes())
        dur = wf.getnframes() / wf.getframerate()
    det_partial = {m: [] for m in MODES}
    det_final = {m: [] for m in MODES}
    seen = {m: 0 for m in MODES}
    cpu0 = time.process_time()
    for off in range(0, len(audio), CHUNK * 2):
        t = (off // 2 + CHUNK) / 16000
        if rec.AcceptWaveform(audio[off: off + CHUNK * 2]):
            res = json.loads(rec.Result())
            words = [w["word"] for w in res.get("result", [])]
            confs = {i: w.get("conf", 0) for i, w in enumerate(res.get("result", []))}
            for m in MODES:
                c = count_mode(words, m)
                if c > seen[m]:
                    det_partial[m] += [t] * (c - seen[m])
                # финальный режим: только уверенные «зак»
                strong = [w if (w not in WAKE_WORDS or confs[i] >= 0.9) else "<low>" for i, w in enumerate(words)]
                if count_mode(strong, m) > 0:
                    det_final[m] += [t] * count_mode(strong, m)
                seen[m] = 0
        else:
            words = json.loads(rec.PartialResult()).get("partial", "").split()
            for m in MODES:
                c = count_mode(words, m)
                if c > seen[m]:
                    det_partial[m] += [t] * (c - seen[m])
                    seen[m] = c
    cpu = time.process_time() - cpu0
    return det_partial, det_final, cpu, dur


def main() -> None:
    RESULTS.mkdir(parents=True, exist_ok=True)
    man = load_manifest()
    out = {"engine": "vosk", "rows": [], "models": {}}
    for lang, mname in MODELS.items():
        mdir = ROOT / "vosk" / mname
        t0 = time.perf_counter()
        rss0 = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        model = vosk.Model(str(mdir))
        load_s = time.perf_counter() - t0
        rss1 = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        size_mb = sum(f.stat().st_size for f in mdir.rglob("*") if f.is_file()) / 1e6
        out["models"][lang] = {"name": mname, "disk_mb": round(size_mb, 1), "load_s": round(load_s, 2),
                               "rss_delta_mb": round((rss1 - rss0) / 1e6, 1)}  # macOS: ru_maxrss в байтах
        for st in [s for s in man["streams"] if s["lang"] == lang]:
            dp, df, cpu, dur = run_stream(model, lang, st["path"])
            for m in MODES:
                for variant, dets in (("partial", dp[m]), ("final_conf", df[m])):
                    r = score(m, st["events"], dur, dets)
                    if r["targets"] == 0:
                        continue
                    out["rows"].append({"lang": lang, "cond": st["cond"], "mode": m, "variant": variant,
                                        "rtf": round(cpu / dur, 4), **r})
            print(f"vosk {lang} {st['cond']}: rtf={cpu / dur:.3f}", flush=True)
    out["peak_rss_mb"] = round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1e6, 1)
    (RESULTS / "vosk.json").write_text(json.dumps(out, ensure_ascii=False, indent=1))
    print("saved", RESULTS / "vosk.json")


if __name__ == "__main__":
    main()
