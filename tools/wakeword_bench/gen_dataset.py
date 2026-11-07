"""
Генерация синтетического набора для сравнения wake word движков (MASTERING-04.1).

Источники речи (только с понятной лицензией, без пользовательских записей):
  - Piper voices (rhasspy/piper-voices): kk_KZ issai/raya/iseke (CC-BY-4.0), ru_RU denis/dmitri (CC0),
    en_US libritts_r (CC-BY-4.0, 904 диктора);
  - системные голоса macOS `say` (локальный синтез на машине разработчика, только для испытаний).
  Исключены: ru_RU ruslan (CC BY-NC-SA), irina (лицензия не указана), en_US lessac (исследовательская).
  Edge TTS не используется (условия сервиса не подтверждают такое использование).

Фоновые условия синтетические: белый/розовый шум, «разговоры» (наложение фраз), «телевизор»
(речь другого диктора тише + шум), «музыка» (синтезированные аккорды). Реальные записи ТВ/музыки
не используются (авторские права) — это ограничение испытаний.

Запуск:  ~/.local/venvs/oww/bin/python tools/wakeword_bench/gen_dataset.py
Результат: ~/.local/share/wakebench/data/{clips,streams}
"""
from __future__ import annotations

import json
import os
import random
import subprocess
import tempfile
import wave
from pathlib import Path

import numpy as np
from scipy.signal import resample_poly

SR = 16000
ROOT = Path(os.path.expanduser("~/.local/share/wakebench"))
PIPER_DIR = ROOT / "piper"
OUT = ROOT / "data"
RNG = random.Random(20261106)
NP_RNG = np.random.default_rng(20261106)

# ---------------------------------------------------------------- дикторы
# split: train — только для обучения openWakeWord; test — только для испытаний (оба движка)
SPEAKERS = {
    "ru": {
        "train": [("piper", "ru_RU-denis-medium", None), ("piper", "kk_KZ-issai-high", None)],
        "test": [("say", "Milena", None), ("piper", "ru_RU-dmitri-medium", None)],
        # акцент: казахский голос говорит по-русски
        "accent": [("piper", "kk_KZ-iseke-x_low", None)],
    },
    "kk": {
        "train": [("piper", "kk_KZ-issai-high", None), ("piper", "kk_KZ-raya-x_low", None)],
        "test": [("piper", "kk_KZ-iseke-x_low", None)],
        # акцент: русский голос говорит по-казахски
        "accent": [("say", "Milena", None)],
    },
    "en": {
        "train": [("piper", "en_US-libritts_r-medium", s) for s in range(0, 450, 9)]
        + [("say", v, None) for v in ("Samantha", "Daniel", "Fred", "Albert")],
        "test": [("piper", "en_US-libritts_r-medium", s) for s in range(455, 904, 15)]
        + [("say", v, None) for v in ("Karen", "Moira", "Rishi", "Tessa", "Kathy")],
        # акцент: русский и казахский голоса произносят английские фразы
        "accent": [("say", "Milena", None), ("piper", "kk_KZ-iseke-x_low", None), ("piper", "ru_RU-dmitri-medium", None)],
    },
}

# ---------------------------------------------------------------- фразы
WAKE = {
    "ru": {"zak": ["Зак.", "Зак!", "Зак?"], "eyzak": ["Эй, Зак.", "Эй, Зак!"], "heyzak": ["Хей, Зак."]},
    "kk": {"zak": ["Зак.", "Зак!"], "eyzak": ["Эй, Зак.", "Әй, Зак!"], "heyzak": ["Хей, Зак."]},
    "en": {"zak": ["Zack.", "Zack!", "Zack?"], "eyzak": [], "heyzak": ["Hey Zack.", "Hey, Zack!"]},
}
COMMANDS = {
    "ru": ["Зак, найди мне айфон до трёхсот тысяч.", "Эй, Зак, где мой заказ?", "Зак, покажи скидки."],
    "kk": ["Зак, маған телефон тауып бер.", "Эй, Зак, тапсырысым қайда?"],
    "en": ["Zack, find me a phone under three hundred thousand.", "Hey Zack, where is my order?"],
}
CONFUSABLE = {
    "ru": ["Зал.", "Так.", "Казак.", "Заказ.", "Знак.", "Закат.", "Сак.", "Вот так.", "Закажи.", "Жак.",
           "Эй, Жак.", "Эй, так.", "Заколка.", "Исаак.", "Зайка.", "Звак.", "Зак-зак-зак ворона.", "Закрой."],
    "kk": ["Зат.", "Сақ.", "Жақ.", "Қазақ.", "Бақ.", "Жақсы.", "Әй, сақ.", "Зақым.", "Заң.", "Зар."],
    "en": ["Jack.", "Sack.", "Back.", "Zag.", "Zap.", "Snack.", "Hey Jack.", "Hey, that.", "Isaac.", "Jazz.",
           "Zigzag.", "Hey Zed.", "Stack.", "Black."],
}
SENTENCES = {
    "ru": ["Привет, как дела?", "Сегодня хорошая погода, пойдём гулять.", "Я заказал новый телефон на маркетплейсе.",
           "Доставка займёт два дня.", "Сколько стоит этот товар?", "Мне нужно купить продукты к ужину.",
           "Он сказал, что так будет лучше.", "Заказ уже в пути, курьер позвонит.", "Включи, пожалуйста, телевизор.",
           "Казаки пели песни у костра.", "В зале было очень шумно.", "Знак остановки стоит на углу.",
           "Закат сегодня особенно красивый.", "Давай посмотрим фильм вечером.", "Цена снизилась на десять процентов.",
           "Я не знаю, что ответить.", "Позвони мне завтра утром.", "Это лучший магазин в городе."],
    "kk": ["Сәлем, қалайсың?", "Бүгін ауа райы жақсы.", "Мен жаңа телефон сатып алдым.", "Жеткізу екі күн алады.",
           "Бұл тауар қанша тұрады?", "Қазақ тілі өте әдемі.", "Тапсырыс жолда келе жатыр.", "Ертең маған хабарлас.",
           "Дүкен кешке жабылады.", "Бағасы он пайызға төмендеді.", "Біз кешке фильм көреміз.", "Сақ бол, жол тайғақ."],
    "en": ["Hi, how are you doing today?", "The weather is nice, let's go for a walk.", "I ordered a new phone online.",
           "Delivery will take two days.", "How much does this item cost?", "I need to buy groceries for dinner.",
           "He said it would be better that way.", "The order is on its way.", "Please turn on the television.",
           "Jack and Isaac went to the store.", "The back of the box was damaged.", "Let's watch a movie tonight.",
           "The price dropped by ten percent.", "Call me tomorrow morning.", "This is the best shop in town.",
           "She packed a snack in her sack."],
}


# ---------------------------------------------------------------- синтез
_voice_cache: dict = {}


def piper_tts(model: str, text: str, speaker: int | None, length_scale: float) -> np.ndarray:
    from piper import PiperVoice, SynthesisConfig

    if model not in _voice_cache:
        _voice_cache[model] = PiperVoice.load(PIPER_DIR / f"{model}.onnx")
    voice = _voice_cache[model]
    with tempfile.NamedTemporaryFile(suffix=".wav") as tmp:
        with wave.open(tmp.name, "wb") as wf:
            voice.synthesize_wav(text, wf, SynthesisConfig(speaker_id=speaker, length_scale=length_scale,
                                                           noise_scale=0.667, noise_w_scale=0.8))
        return read_wav(tmp.name)


def say_tts(voice: str, text: str, rate: int) -> np.ndarray:
    with tempfile.NamedTemporaryFile(suffix=".wav") as tmp:
        subprocess.run(["say", "-v", voice, "-r", str(rate), "--file-format=WAVE", "--data-format=LEI16@16000",
                        "-o", tmp.name, text], check=True, capture_output=True)
        return read_wav(tmp.name)


def read_wav(path: str) -> np.ndarray:
    with wave.open(path, "rb") as wf:
        sr, ch, n = wf.getframerate(), wf.getnchannels(), wf.getnframes()
        data = np.frombuffer(wf.readframes(n), dtype=np.int16).astype(np.float32) / 32768.0
    if ch > 1:
        data = data.reshape(-1, ch).mean(axis=1)
    if sr != SR:
        g = np.gcd(sr, SR)
        data = resample_poly(data, SR // g, sr // g).astype(np.float32)
    return trim(data)


def trim(x: np.ndarray, thr: float = 0.01) -> np.ndarray:
    idx = np.where(np.abs(x) > thr)[0]
    if len(idx) == 0:
        return x
    return x[max(0, idx[0] - 800): idx[-1] + 1600]


def speak(spk: tuple, text: str, speed: float = 1.0) -> np.ndarray:
    kind, name, sid = spk
    if kind == "piper":
        return piper_tts(name, text, sid, length_scale=1.0 / speed)
    return say_tts(name, text, int(185 * speed))


def write_wav(path: Path, x: np.ndarray) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    y = np.clip(x, -1, 1)
    with wave.open(str(path), "wb") as wf:
        wf.setnchannels(1)
        wf.setsampwidth(2)
        wf.setframerate(SR)
        wf.writeframes((y * 32767).astype(np.int16).tobytes())


def rms(x: np.ndarray) -> float:
    return float(np.sqrt(np.mean(x ** 2)) + 1e-9)


# ---------------------------------------------------------------- фоновые звуки
def pink_noise(n: int) -> np.ndarray:
    white = NP_RNG.standard_normal(n)
    f = np.fft.rfft(white)
    f /= np.sqrt(np.arange(1, len(f) + 1))
    p = np.fft.irfft(f, n)
    return (p / (np.max(np.abs(p)) + 1e-9) * 0.5).astype(np.float32)


def synth_music(seconds: float) -> np.ndarray:
    """Синтезированная «музыка»: аккорды с ритмом и мелодией (без чужих записей)."""
    n = int(seconds * SR)
    t = np.arange(n) / SR
    out = np.zeros(n, dtype=np.float32)
    chords = [[220, 277, 330], [196, 247, 294], [174, 220, 262], [247, 311, 370]]
    beat = 0.5
    for i in range(int(seconds / beat)):
        s, e = int(i * beat * SR), int((i + 1) * beat * SR)
        for f in chords[(i // 4) % len(chords)]:
            out[s:e] += 0.08 * np.sin(2 * np.pi * f * t[s:e]) * np.exp(-3 * (t[s:e] - t[s]))
        mel = chords[(i // 4) % len(chords)][i % 3] * 2
        out[s:e] += 0.05 * np.sign(np.sin(2 * np.pi * mel * t[s:e])) * np.exp(-6 * (t[s:e] - t[s]))
        out[s:s + 400] += 0.2 * NP_RNG.standard_normal(min(400, e - s)).astype(np.float32)  # «ударные»
    return out


def mix_at_snr(signal: np.ndarray, noise: np.ndarray, snr_db: float) -> np.ndarray:
    if len(noise) < len(signal):
        noise = np.tile(noise, int(np.ceil(len(signal) / len(noise))))
    noise = noise[: len(signal)]
    k = rms(signal) / (rms(noise) * 10 ** (snr_db / 20))
    return signal + k * noise


# ---------------------------------------------------------------- клипы для обучения openWakeWord
def build_clips(lang: str, split: str) -> list[dict]:
    """Короткие клипы: позитивы по фразам, негативы (похожие слова, речь)."""
    items = []
    speeds = [0.85, 1.0, 1.2]
    speakers = SPEAKERS[lang][split] + (SPEAKERS[lang].get("accent", []) if split == "test" else [])
    test_speeds = speeds if lang in ("ru", "kk") else [1.0]
    for spk in speakers:
        spk_id = f"{spk[0]}:{spk[1]}:{spk[2]}"
        for phrase, texts in WAKE[lang].items():
            for text in texts:
                for sp in (speeds if split == "train" else test_speeds):
                    items.append({"label": phrase, "text": text, "spk": spk_id, "audio": speak(spk, text, sp)})
        for text in CONFUSABLE[lang] + SENTENCES[lang]:
            items.append({"label": "neg", "text": text, "spk": spk_id, "audio": speak(spk, text, 1.0)})
    return items


# ---------------------------------------------------------------- тестовые потоки
CONDITIONS = {
    "clean": {"snr": None, "gain": 1.0},
    "noise10": {"snr": 10, "gain": 1.0, "bg": "pink"},
    "babble5": {"snr": 5, "gain": 1.0, "bg": "babble"},
    "tv": {"snr": 6, "gain": 1.0, "bg": "tv"},
    "music": {"snr": 5, "gain": 1.0, "bg": "music"},
    "quiet": {"snr": 20, "gain": 0.08, "bg": "pink"},
    "loud": {"snr": 25, "gain": 3.5, "bg": "pink"},
}


def build_stream(lang: str, cond: str, test_items: list[dict], other_speech: list[np.ndarray]) -> tuple[np.ndarray, list[dict]]:
    cfg = CONDITIONS[cond]
    pos = [it for it in test_items if it["label"] != "neg"]
    neg = [it for it in test_items if it["label"] == "neg"]
    segments: list[tuple[np.ndarray, dict]] = []
    # позитивы: каждое тестовое произнесение + команды с обращением в начале
    for it in pos:
        segments.append((it["audio"], {"kind": "wake", "phrase": it["label"], "text": it["text"], "spk": it["spk"]}))
    for spk in SPEAKERS[lang]["test"]:
        for cmd in COMMANDS[lang]:
            phrase = "eyzak" if cmd.lower().startswith(("эй", "әй")) else ("heyzak" if cmd.lower().startswith("hey") else "zak")
            segments.append((speak(spk, cmd), {"kind": "wake_cmd", "phrase": phrase, "text": cmd, "spk": f"{spk[0]}:{spk[1]}"}))
    # негативы (каждый дважды) + синтетическая музыка как самостоятельные отрезки
    for it in neg * 2:
        segments.append((it["audio"], {"kind": "neg", "text": it["text"], "spk": it["spk"]}))
    for _ in range(6):
        segments.append((synth_music(RNG.uniform(6, 12)) * 0.6, {"kind": "music"}))
    RNG.shuffle(segments)

    parts, events, t = [], [], 0.0
    for audio, meta in segments:
        gap = np.zeros(int(RNG.uniform(0.6, 1.6) * SR), dtype=np.float32)
        parts.append(gap)
        t += len(gap) / SR
        meta = dict(meta, start=round(t, 3), end=round(t + len(audio) / SR, 3))
        events.append(meta)
        parts.append(audio.astype(np.float32))
        t += len(audio) / SR
    parts.append(np.zeros(SR * 2, dtype=np.float32))
    x = np.concatenate(parts) * cfg["gain"]

    if cfg.get("snr") is not None:
        n = len(x)
        bg = cfg.get("bg", "pink")
        if bg == "pink":
            noise = pink_noise(n)
        elif bg == "music":
            noise = synth_music(n / SR + 1)[:n]
        else:
            babble = np.zeros(n, dtype=np.float32)
            for _ in range(3 if bg == "babble" else 1):
                off = 0
                while off < n:
                    s = other_speech[RNG.randrange(len(other_speech))]
                    take = min(len(s), n - off)
                    babble[off:off + take] += s[:take]
                    off += take + int(RNG.uniform(0.1, 0.5) * SR)
            noise = babble + (0.3 * pink_noise(n) if bg == "tv" else 0)
        speech_rms_ref = np.concatenate([p for p in parts if rms(p) > 1e-4]) * cfg["gain"]
        k = rms(speech_rms_ref) / (rms(noise) * 10 ** (cfg["snr"] / 20))
        x = x + k * noise
    if cfg["gain"] > 1:
        x = np.tanh(x)  # мягкое ограничение громкой речи
    return x.astype(np.float32), events


def main() -> None:
    import sys
    OUT.mkdir(parents=True, exist_ok=True)
    langs = sys.argv[1:] or ["ru", "kk", "en"]
    mpath = OUT / "manifest.json"
    manifest = json.loads(mpath.read_text()) if mpath.exists() else {"sr": SR, "streams": [], "clips": {}}
    manifest["streams"] = [s for s in manifest["streams"] if s["lang"] not in langs]
    for lang in langs:
        print(f"[{lang}] synthesizing clips…", flush=True)
        train = build_clips(lang, "train")
        test = build_clips(lang, "test")
        for split, items in (("train", train), ("test", test)):
            recs = []
            for i, it in enumerate(items):
                p = OUT / "clips" / lang / split / f"{it['label']}_{i:05d}.wav"
                write_wav(p, it["audio"])
                recs.append({"path": str(p), "label": it["label"], "text": it["text"], "spk": it["spk"]})
            manifest["clips"][f"{lang}_{split}"] = recs
        other = [it["audio"] for it in train if it["label"] == "neg" and len(it["audio"]) > SR]
        for cond in CONDITIONS:
            x, events = build_stream(lang, cond, test, other)
            p = OUT / "streams" / f"{lang}_{cond}.wav"
            write_wav(p, x)
            manifest["streams"].append({"lang": lang, "cond": cond, "path": str(p),
                                        "duration_s": round(len(x) / SR, 2), "events": events})
            print(f"  stream {lang}_{cond}: {len(x) / SR / 60:.1f} min, "
                  f"{sum(e['kind'].startswith('wake') for e in events)} wake events", flush=True)
    (OUT / "manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=1))
    print("done")


if __name__ == "__main__":
    main()
