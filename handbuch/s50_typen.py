"""Übung #2 (Turtle, Robo, Eingabe) und Test #3 (alle Diagrammtypen) in der Schüleransicht, mit ausgefüllten Antworten."""
import json
from kw import *
from s30c_pult import step, btn
from s30b_schueler import pick, typecode

DLG = "dialog[open] button.btn-primary, [role=dialog] button.btn-primary, .modal button.btn-primary"


def overlay(p):
    ov = p.get_by_role("button", name=re.compile("Klicke, um zu beginnen|beginnen|Weiter im Vollbild"))
    if ov.count() and ov.first.is_visible():
        ov.first.click()
        p.wait_for_timeout(800)


TURTLE = "import turtle\nt = turtle.Turtle()\nfor i in range(4):\n    t.forward(100)\n    t.left(90)\n"
ROBO = "from robo import *\n\nvor()\nvor()\nrechts()\nvor()\n"


def box(i, name, x, y, attrs=(), methods=(), kind="class"):
    return {"id": i, "name": name, "kind": kind, "x": x, "y": y,
            "attributes": [{"visibility": "-", "name": a, "type": t, "static": False} for a, t in attrs],
            "methods": [{"visibility": "+", "name": m, "params": p, "return_type": r, "static": False} for m, p, r in methods]}


CONTENT = {
    "uml": {"classes": [
        box("c1", "Buch", 60, 60, [("titel", "String"), ("isbn", "String")], [("getTitel", "", "String")]),
        box("c2", "Ausleihe", 340, 60, [("datum", "Date")], [("verlaengern", "int tage", "void")]),
        box("c3", "Mitglied", 620, 60, [("name", "String")], [("getName", "", "String")])],
        "relations": [{"id": "r1", "from": "c2", "to": "c1", "kind": "association", "label": "betrifft", "mult_from": "*", "mult_to": "1", "route": "straight"},
                      {"id": "r2", "from": "c2", "to": "c3", "kind": "association", "label": "tätigt", "mult_from": "*", "mult_to": "1", "route": "straight"}]},
    "structogram": {"root": {"type": "sequence", "id": "s0", "children": [
        {"type": "statement", "id": "a1", "text": "i := 0"},
        {"type": "while", "id": "a2", "cond": "i < n und feld[i] != x", "body": {"type": "sequence", "id": "s1", "children": [{"type": "statement", "id": "a3", "text": "i := i + 1"}]}},
        {"type": "if", "id": "a4", "cond": "i < n", "then": {"type": "sequence", "id": "s2", "children": [{"type": "statement", "id": "a5", "text": "gib i aus"}]},
         "else": {"type": "sequence", "id": "s3", "children": [{"type": "statement", "id": "a6", "text": "gib -1 aus"}]}}]}},
    "flowchart": {"nodes": [
        {"id": "n1", "type": "start", "text": "Start", "x": 300, "y": 30},
        {"id": "n2", "type": "io", "text": "Lies z", "x": 300, "y": 110},
        {"id": "n3", "type": "decision", "text": "z teilbar durch 2..z-1?", "x": 300, "y": 200},
        {"id": "n4", "type": "io", "text": "keine Primzahl", "x": 120, "y": 320},
        {"id": "n5", "type": "io", "text": "Primzahl", "x": 480, "y": 320},
        {"id": "n6", "type": "end", "text": "Ende", "x": 300, "y": 420}],
        "edges": [{"id": "e1", "from": "n1", "to": "n2", "label": ""}, {"id": "e2", "from": "n2", "to": "n3", "label": ""},
                  {"id": "e3", "from": "n3", "to": "n4", "label": "ja"}, {"id": "e4", "from": "n3", "to": "n5", "label": "nein"},
                  {"id": "e5", "from": "n4", "to": "n6", "label": ""}, {"id": "e6", "from": "n5", "to": "n6", "label": ""}]},
    "logic": {"components": [
        {"id": "i1", "type": "INPUT", "label": "A", "x": 60, "y": 80}, {"id": "i2", "type": "INPUT", "label": "B", "x": 60, "y": 220},
        {"id": "g1", "type": "XOR", "label": "", "x": 300, "y": 150}, {"id": "o1", "type": "OUTPUT", "label": "X", "x": 540, "y": 150}],
        "wires": [{"id": "w1", "from": {"component": "i1", "pin": "out"}, "to": {"component": "g1", "pin": "in0"}},
                  {"id": "w2", "from": {"component": "i2", "pin": "out"}, "to": {"component": "g1", "pin": "in1"}},
                  {"id": "w3", "from": {"component": "g1", "pin": "out"}, "to": {"component": "o1", "pin": "in0"}}]},
    "truth_table": {"rows": {"00": {"X": "0"}, "01": {"X": "0"}, "10": {"X": "1"}, "11": {"X": "0"}}},
    "automaton": {"states": [
        {"id": "q0", "name": "q0", "x": 100, "y": 150, "initial": True, "accepting": False},
        {"id": "q1", "name": "q1", "x": 300, "y": 150, "initial": False, "accepting": False},
        {"id": "q2", "name": "q2", "x": 500, "y": 150, "initial": False, "accepting": True}],
        "transitions": [{"id": "t1", "from": "q0", "to": "q1", "symbols": ["a"]}, {"id": "t2", "from": "q1", "to": "q2", "symbols": ["b"]},
                        {"id": "t3", "from": "q0", "to": "q0", "symbols": ["b"]}, {"id": "t4", "from": "q1", "to": "q1", "symbols": ["a"]},
                        {"id": "t5", "from": "q2", "to": "q1", "symbols": ["a"]}, {"id": "t6", "from": "q2", "to": "q0", "symbols": ["b"]}]},
    "er": {"entities": [{"id": "e1", "name": "Schüler", "x": 120, "y": 180, "weak": False}, {"id": "e2", "name": "Kurs", "x": 560, "y": 180, "weak": False}],
           "attributes": [{"id": "a1", "owner": "e1", "name": "SNr", "key": True, "x": 60, "y": 60}, {"id": "a2", "owner": "e1", "name": "Name", "key": False, "x": 190, "y": 60},
                          {"id": "a3", "owner": "e2", "name": "KNr", "key": True, "x": 500, "y": 60}, {"id": "a4", "owner": "e2", "name": "Titel", "key": False, "x": 630, "y": 60}],
           "relationships": [{"id": "r1", "name": "belegt", "x": 340, "y": 180, "ends": [{"entity": "e1", "cardinality": "n"}, {"entity": "e2", "cardinality": "m"}]}]},
    "relational": {"tables": [
        {"id": "t1", "name": "Schüler", "columns": [{"name": "SNr", "pk": True, "fk": None}, {"name": "Name", "pk": False, "fk": None}]},
        {"id": "t2", "name": "Kurs", "columns": [{"name": "KNr", "pk": True, "fk": None}, {"name": "Titel", "pk": False, "fk": None}]},
        {"id": "t3", "name": "belegt", "columns": [{"name": "SNr", "pk": True, "fk": "Schüler.SNr"}, {"name": "KNr", "pk": True, "fk": "Kurs.KNr"}]}]},
    "drawing": {"strokes": [{"color": "#3730a3", "width": 3, "points": [[60, 300], [120, 220], [180, 300], [240, 220], [300, 300]]}],
                "texts": [{"id": "x1", "x": 80, "y": 120, "text": "Zickzack-Skizze", "color": "#12142a"}], "upload_ids": []},
}
TASKS3 = {"uml": 13, "structogram": 14, "flowchart": 15, "logic": 16, "truth_table": 17, "automaton": 18, "er": 19, "relational": 20, "drawing": 21}
TITLES3 = {"uml": "Klassendiagramm Bibliothek", "structogram": "Struktogramm", "flowchart": "Programmablaufplan", "logic": "Schaltung",
           "truth_table": "Wahrheitstabelle", "automaton": "Endlicher Automat", "er": "ER-Modell", "relational": "Relationenschema", "drawing": "Skizze"}


def main(b):
    # --- Übung #2: Aufnahmen
    m = sess(b, "mia.becker")
    goto(m, "/klausur/2", 2000)
    step("join", lambda: m.get_by_role("button", name=re.compile("Beitreten|Bereit|Starten|Los")).first.click())
    m.wait_for_timeout(2500)
    overlay(m)
    shot(m, "x-540-uebung-start")
    pick(m, "Quersumme")
    typecode(m, "zahl = input(\"Zahl: \")\nprint(sum(int(z) for z in zahl))\n")
    shot(m, "x-541-uebung-python-beispieltests")
    pick(m, "Turtle: Quadrat")
    typecode(m, TURTLE)
    m.get_by_role("button", name=re.compile("Ausführen")).first.click()
    m.wait_for_timeout(8000)
    shot(m, "x-542-turtle")
    pick(m, "Robo sammelt Schrauben")
    shot(m, "x-543-robo-start")
    typecode(m, ROBO)
    m.get_by_role("button", name=re.compile("Ausführen")).first.click()
    m.wait_for_timeout(6000)
    shot(m, "x-544-robo-lauf")
    m.context.storage_state(path=str(STATE / "mia.becker.json"))

    # --- Test #3: Lobby, Beitritt, Start
    t = sess(b, "frau.lehmann")
    goto(t, "/klausuren/3/pult", 1500)
    step("lobby", lambda: t.get_by_role("button", name=re.compile("Warteraum öffnen")).click())
    t.wait_for_timeout(1500)
    mm = sess(b, "mia.becker")
    goto(mm, "/klausur/3", 1500)
    step("bereit", lambda: mm.get_by_role("button", name=re.compile("^Bereit")).click())
    mm.wait_for_timeout(1500)
    mm.context.storage_state(path=str(STATE / "mia.becker.json"))
    step("start", lambda: t.get_by_role("button", name=re.compile("Starten|Klausur starten")).first.click())
    t.wait_for_timeout(800)
    step("start2", lambda: t.get_by_role("button", name="Jetzt starten").click())
    t.wait_for_timeout(3500)
    overlay(mm)
    mm.wait_for_timeout(1500)
    items, contents, seq = [], {}, 1
    for k, tid in TASKS3.items():
        items.append({"seq": seq, "task_id": tid, "client_ts": 0, "kind": "value", "payload": CONTENT[k]})
        contents[str(tid)] = {"content": CONTENT[k], "flagged": False}
        seq += 1
    res = post_json(mm, "/api/klausur/3/speichern", {"items": items, "contents": contents, "client_now_ms": mm.evaluate("Date.now()")})
    print("speichern", json.dumps(res)[:600])
    goto(mm, "/klausur/3", 2500)
    overlay(mm)
    for k, title in TITLES3.items():
        step(k, lambda: pick(mm, title))
        mm.wait_for_timeout(900)
        shot(mm, f"x-550-schueler-{k}")
    mm.context.storage_state(path=str(STATE / "mia.becker.json"))


if __name__ == "__main__":
    run(main)
