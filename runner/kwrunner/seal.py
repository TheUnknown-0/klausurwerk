"""Startet ein Programm mit seccomp-Filter: ``python3 -I -S seal.py <programm> <argumente...>``.

Gleichzeitige Läufe teilen den Netzwerk- und IPC-Namensraum des Containers. Damit zwei
Schülerprogramme nicht über ``localhost``, abstrakte Unix-Sockets, SysV-IPC oder POSIX-
Message-Queues miteinander reden können, verbietet dieser Filter die zugehörigen
Systemaufrufe (Rückgabe ``EACCES``). Er braucht keine zusätzliche Capability, nur
``no_new_privs`` (das Docker mit ``no-new-privileges`` ohnehin setzt) und bleibt über
``execve`` hinweg bestehen. ``socketpair`` und Pipes bleiben erlaubt, sie verbinden nur
Prozesse desselben Laufs.

Unterstützt sind x86_64 und aarch64. Auf anderen Architekturen startet das Programm ohne
Filter (der Runner meldet das beim Start einmal im Log).
"""
import ctypes
import os
import struct
import sys

PR_SET_NO_NEW_PRIVS = 38
PR_SET_SECCOMP = 22
SECCOMP_MODE_FILTER = 2
RET_ALLOW = 0x7FFF0000
RET_EACCES = 0x00050000 | 13

# Gemeinsame Nummern (neue Tabelle, auf beiden Architekturen gleich).
IO_URING = (425, 426, 427)  # io_uring_setup, _enter, _register (Sockets am Filter vorbei)

ARCHES = {
    # Architektur: (AUDIT_ARCH, x32-Bit prüfen, verbotene Aufrufe)
    "x86_64": (0xC000003E, True, (41,                # socket
                                  29, 64, 68,        # shmget, semget, msgget
                                  240) + IO_URING),  # mq_open
    "aarch64": (0xC00000B7, False, (198,             # socket
                                    194, 190, 186,   # shmget, semget, msgget
                                    180) + IO_URING),  # mq_open
}


def program(machine: str) -> list:
    """BPF-Programm als Liste (code, jt, jf, k)."""
    arch, x32, denied = ARCHES[machine]
    ld_arch = (0x20, 0, 0, 4)   # A = seccomp_data.arch
    ld_nr = (0x20, 0, 0, 0)     # A = seccomp_data.nr
    body = [ld_nr]
    if x32:
        body.append(("jge", 0x40000000))
    for nr in denied:
        body.append(("jeq", nr))
    insns = [ld_arch, ("arch", arch)] + body
    count = len(insns)
    # Am Ende: ALLOW, dann EACCES. Sprünge relativ zur nächsten Anweisung.
    out = []
    for index, insn in enumerate(insns):
        to_allow = count - index - 1
        to_deny = to_allow + 1
        if insn[0] == "arch":
            out.append((0x15, 0, to_deny, insn[1]))       # fremde Architektur: verbieten
        elif insn[0] == "jge":
            out.append((0x35, to_deny, 0, insn[1]))       # x32-Aufrufe: verbieten
        elif insn[0] == "jeq":
            out.append((0x15, to_deny, 0, insn[1]))
        else:
            out.append(insn)
    out.append((0x06, 0, 0, RET_ALLOW))
    out.append((0x06, 0, 0, RET_EACCES))
    return out


def install(machine: str) -> bool:
    if machine not in ARCHES:
        return False
    insns = program(machine)
    raw = b"".join(struct.pack("HBBI", *insn) for insn in insns)
    buf = ctypes.create_string_buffer(raw, len(raw))

    class Prog(ctypes.Structure):
        _fields_ = [("len", ctypes.c_ushort), ("filter", ctypes.c_void_p)]

    prog = Prog(len(insns), ctypes.addressof(buf))
    libc = ctypes.CDLL(None, use_errno=True)
    libc.prctl.argtypes = [ctypes.c_int, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong]
    if libc.prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0:
        raise OSError(ctypes.get_errno(), "PR_SET_NO_NEW_PRIVS")
    if libc.prctl(PR_SET_SECCOMP, SECCOMP_MODE_FILTER, ctypes.addressof(prog), 0, 0) != 0:
        raise OSError(ctypes.get_errno(), "PR_SET_SECCOMP")
    return True


def main() -> None:
    if len(sys.argv) < 2:
        sys.stderr.write("Aufruf: seal.py <programm> [argumente]\n")
        os._exit(64)
    try:
        install(os.uname().machine)
    except OSError as exc:
        # Lieber nicht starten als ungeschützt: Der Runner meldet den Fehler als Lauf-Fehler.
        sys.stderr.write("Die Ausführungsumgebung konnte nicht abgesichert werden (" + str(exc) + ").\n")
        os._exit(70)
    try:
        os.execvp(sys.argv[1], sys.argv[1:])
    except OSError as exc:
        sys.stderr.write("Das Programm konnte nicht gestartet werden (" + str(exc.strerror) + ").\n")
        os._exit(127)


if __name__ == "__main__":
    main()
