"""Klausur #1, Teil B: Schüleransicht ausfüllen und aufnehmen (Mia). Läuft nach s30a."""
from kw import *

PY = """def fib(n):
    a, b = 0, 1
    for _ in range(n):
        a, b = b, a + b
    return a

n = int(input("n = "))
print(fib(n))
"""
JAVA = """public class Konto {
    private int kontostand;

    public void einzahlen(int betrag) {
        kontostand += betrag;
    }

    public boolean abheben(int betrag) {
        if (betrag <= kontostand) {
            kontostand -= betrag;
            return true;
        }
        return false;
    }

    public int getKontostand() {
        return kontostand;
    }
}
"""
SQL = "SELECT titel, jahr FROM buch WHERE ausgeliehen = 0 ORDER BY jahr DESC;\n"


def pick(p, title):
    p.locator("nav, aside, [class*=sidebar], [class*=tasknav]").get_by_text(title, exact=True).first.click()
    p.wait_for_timeout(900)


def typecode(p, code):
    p.locator(".cm-content:visible").first.click()
    p.keyboard.press("Control+A")
    p.keyboard.insert_text(code)
    p.wait_for_timeout(400)


def main(b):
    p = sess(b, "mia.becker")
    goto(p, "/klausur/1", 1500)
    ov = p.get_by_role("button", name=re.compile("beginnen|Weiter im Vollbild|Vollbild"))
    if ov.count() and ov.first.is_visible():
        ov.first.click(); p.wait_for_timeout(1000)
    # Grundlagen
    pick(p, "1a")  if False else None
    boxes = p.locator("input[type=checkbox]")
    boxes.nth(1).check(); boxes.nth(3).check()
    p.locator("select").first.select_option(index=1)
    p.wait_for_timeout(300)
    shot(p, "x-510-aufgabe-choice-gaps-text")
    ta = p.locator("textarea").first
    ta.fill("Im schlechtesten Fall steht das gesuchte Element ganz hinten oder fehlt. Dann muss jedes der n Elemente einmal verglichen werden.")
    p.wait_for_timeout(500)
    # Python
    pick(p, "Fibonacci in Python")
    typecode(p, PY)
    shot(p, "x-511-code-python-vor-lauf")
    p.get_by_role("button", name=re.compile("Ausführen")).first.click()
    p.wait_for_timeout(9000)
    shot(p, "x-512-code-python-eingabe")
    inp = p.locator("input.console-input, .console input, [class*=console] input, [class*=console] textarea").first
    inp.fill("10"); inp.press("Enter")
    p.wait_for_timeout(2500)
    shot(p, "x-513-code-python-ausgabe")
    # Java
    pick(p, "Klasse Konto")
    p.get_by_text("Konto.java", exact=True).first.click(); p.wait_for_timeout(500)
    typecode(p, JAVA)
    p.get_by_role("button", name=re.compile("Ausführen")).first.click()
    p.wait_for_timeout(9000)
    shot(p, "x-514-code-java-ausgabe")
    # SQL
    pick(p, "SQL: Bibliothek")
    typecode(p, SQL)
    p.get_by_role("button", name=re.compile("Ausführen")).first.click()
    p.wait_for_timeout(5000)
    shot(p, "x-515-code-sql-ergebnis")
    # Zuordnung
    pick(p, "Begriffe")
    shot(p, "x-516-matching-leer")
    sels = p.locator("main select, .exam-main select, select")
    n = sels.count()
    for i in range(n):
        sels.nth(i).select_option(index=min(i + 1, 3))
    p.wait_for_timeout(500)
    shot(p, "x-517-matching")
    pick(p, "Bubblesort")
    shot(p, "x-518-ordering")
    p.wait_for_timeout(6000)
    pick(p, "Grundlagen")
    shot(p, "x-519-aufgabenleiste-fortschritt")
    p.context.storage_state(path=str(STATE / "mia.becker.json"))


if __name__ == "__main__":
    run(main)
