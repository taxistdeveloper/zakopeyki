"""Общие метрики сравнения wake word: TP / пропуски / ложные срабатывания / задержка."""
from __future__ import annotations

import json
import os
from pathlib import Path

ROOT = Path(os.path.expanduser("~/.local/share/wakebench"))
DATA = ROOT / "data"
RESULTS = ROOT / "results"

# Окно засчитывания: от начала произнесения до конца + 1.5 с
HIT_TAIL_S = 1.5
# Повторные срабатывания внутри этого интервала объединяются (cooldown детектора)
REFRACTORY_S = 1.5

MODES = ("zak", "eyzak", "heyzak")


def load_manifest() -> dict:
    return json.loads((DATA / "manifest.json").read_text())


def positives_for(mode: str, events: list[dict]) -> tuple[list[dict], list[dict]]:
    """Целевые события режима и «нейтральные» (другие варианты обращения — не TP и не FA)."""
    wake = [e for e in events if e["kind"].startswith("wake")]
    if mode == "zak":
        return wake, []
    target = [e for e in wake if e["phrase"] == mode]
    neutral = [e for e in wake if e["phrase"] != mode]
    return target, neutral


def debounce(times: list[float]) -> list[float]:
    out: list[float] = []
    for t in sorted(times):
        if not out or t - out[-1] >= REFRACTORY_S:
            out.append(t)
    return out


def score(mode: str, events: list[dict], duration_s: float, detections: list[float]) -> dict:
    target, neutral = positives_for(mode, events)
    dets = debounce(detections)
    hit: dict[int, float] = {}
    false_alarms: list[dict] = []
    for t in dets:
        matched = False
        for i, e in enumerate(target):
            if e["start"] - 0.2 <= t <= e["end"] + HIT_TAIL_S:
                if i not in hit:
                    hit[i] = t
                matched = True
                break
        if matched:
            continue
        if any(e["start"] - 0.2 <= t <= e["end"] + HIT_TAIL_S for e in neutral):
            continue
        near = next((e for e in events if e["start"] - 0.3 <= t <= e["end"] + 1.0), None)
        false_alarms.append({"t": round(t, 2), "near": (near or {}).get("text", (near or {}).get("kind", "silence/gap"))})

    pos_time = sum(e["end"] + HIT_TAIL_S - e["start"] for e in target + neutral)
    neg_hours = max(1e-9, (duration_s - pos_time) / 3600)
    lat = [hit[i] - target[i]["end"] for i in hit if target[i]["kind"] == "wake"]
    n = len(target)
    return {
        "targets": n,
        "hits": len(hit),
        "misses": n - len(hit),
        "miss_rate": round((n - len(hit)) / n, 3) if n else None,
        "false_alarms": len(false_alarms),
        "fa_per_hour": round(len(false_alarms) / neg_hours, 2),
        "neg_minutes": round(neg_hours * 60, 1),
        "latency_ms_median": round(sorted(lat)[len(lat) // 2] * 1000) if lat else None,
        "latency_ms_p90": round(sorted(lat)[int(len(lat) * 0.9)] * 1000) if lat else None,
        "fa_examples": [f["near"] for f in false_alarms[:8]],
    }
