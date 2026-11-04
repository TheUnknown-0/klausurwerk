"""Einstiegspunkt: ``python3 -I -B /opt/runner/kwrunner/main.py``."""
import os
import signal
import sys
import threading

# Mit -I steht das Paketverzeichnis nicht auf dem Suchpfad.
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from kwrunner.config import Config  # noqa: E402
from kwrunner.httpd import serve  # noqa: E402
from kwrunner.sandbox import isolation_active, reap_loop, seal_active  # noqa: E402


def main() -> None:
    cfg = Config.from_env()
    os.makedirs(cfg.work_dir, exist_ok=True)
    if isolation_active():
        # Nicht listbar: Läufe sehen weder die Namen noch die Zahl fremder Laufverzeichnisse.
        os.chmod(cfg.work_dir, 0o711)
        # Gemeinsame Ablagen für Lauf-UIDs sperren: Jeder Lauf hat ein eigenes Home und TMPDIR,
        # über /tmp oder /dev/shm sollen zwei gleichzeitige Läufe keine Dateien tauschen.
        for shared in ("/tmp", "/dev/shm"):
            try:
                os.chmod(shared, 0o711)
            except OSError:
                pass
    if not seal_active():
        sys.stderr.write("Hinweis: Läufe starten ohne seccomp-Filter (Plattform oder RUNNER_SEAL=0).\n")
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
