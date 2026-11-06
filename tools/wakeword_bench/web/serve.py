"""Локальный сервер изолированного стенда (COOP/COEP для measureUserAgentSpecificMemory).

~/.local/venvs/oww/bin/python tools/wakeword_bench/web/serve.py  → http://localhost:8765/bench.html
Отдаёт ~/.local/share/wakebench/web (модели, node_modules) и ~/.local/share/wakebench/data/streams.
"""
import functools
import http.server
import os
import shutil
from pathlib import Path

ROOT = Path(os.path.expanduser("~/.local/share/wakebench"))
WEB = ROOT / "web"
HERE = Path(__file__).resolve().parent


class Handler(http.server.SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header("Cross-Origin-Opener-Policy", "same-origin")
        self.send_header("Cross-Origin-Embedder-Policy", "require-corp")
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, *args):
        pass


if __name__ == "__main__":
    for f in ("bench.html", "bench.js"):
        shutil.copy(HERE / f, WEB / f)
    streams = WEB / "streams"
    if not streams.exists():
        streams.symlink_to(ROOT / "data" / "streams")
    results = WEB / "results"
    if not results.exists():
        results.symlink_to(ROOT / "results")
    http.server.ThreadingHTTPServer(("127.0.0.1", 8765), functools.partial(Handler, directory=str(WEB))).serve_forever()
