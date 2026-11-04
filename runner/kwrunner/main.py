"""Einstiegspunkt: ``python3 -I -B /opt/runner/kwrunner/main.py``."""
import os
import signal
import sys
import threading

# Mit -I steht das Paketverzeichnis nicht auf dem Suchpfad.
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from kwrunner.config import Config  # noqa: E402
from kwrunner.httpd import serve  # noqa: E402
from kwrunner.sandbox import reap_loop  # noqa: E402


def main() -> None:
    cfg = Config.from_env()
    os.makedirs(cfg.work_dir, exist_ok=True)
    if not cfg.token:
        sys.stderr.write("Warnung: RUNNER_TOKEN ist nicht gesetzt, alle Anfragen ausser /healthz werden abgelehnt.\n")
    # Reste eines früheren Starts (nur relevant ohne frisches tmpfs).
    for name in os.listdir(cfg.work_dir):
        if name.startswith(("r_", "t_")):
            import shutil
            shutil.rmtree(os.path.join(cfg.work_dir, name), ignore_errors=True)
    threading.Thread(target=reap_loop, daemon=True).start()
    server = serve(cfg)

    def stop(*_):
        threading.Thread(target=server.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    sys.stderr.write(f"Runner lauscht auf Port {cfg.port} (parallel {cfg.max_parallel}, "
                     f"{cfg.max_seconds} s, {cfg.max_memory_mb} MB)\n")
    server.serve_forever()


if __name__ == "__main__":
    main()
