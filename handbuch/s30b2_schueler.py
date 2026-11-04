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
    pick(p, "Begriffe")
    sels = p.locator("select:visible")
    for i in range(sels.count()):
        sels.nth(i).select_option(index=i + 1)
    p.wait_for_timeout(500)
    shot(p, "x-517-matching")
    pick(p, "Bubblesort")
    shot(p, "x-518-ordering")
    p.locator("button[aria-label*='nach unten'], button:has-text('↓')").first.click()
    p.wait_for_timeout(6000)
    pick(p, "Grundlagen")
    shot(p, "x-519-aufgabenleiste-fortschritt")
    p.context.storage_state(path=str(STATE / "mia.becker.json"))


if __name__ == "__main__":
    run(main)
