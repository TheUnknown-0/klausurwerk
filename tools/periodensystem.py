"""Erzeugt public/assets/aids/elements.json für das Hilfsmittel "Periodensystem".

Quelle: PubChem Periodic Table (gemeinfrei, US-Regierungswerk),
https://pubchem.ncbi.nlm.nih.gov/rest/pug/periodictable/CSV

Aufruf: python tools/periodensystem.py <pfad/zur/pubchem.csv>
Ergebnis: Ordnungszahl, Symbol, deutscher Name, Atommasse, Elektronegativität (Pauling),
Elektronenkonfiguration, Elementgruppe (deutsch), Aggregatzustand, Position (Periode/Gruppe;
Lanthanoide und Actinoide in Zeile 9 und 10).
"""
import csv
import json
import sys
from pathlib import Path

NAMES = (
    'Wasserstoff Helium Lithium Beryllium Bor Kohlenstoff Stickstoff Sauerstoff Fluor Neon '
    'Natrium Magnesium Aluminium Silicium Phosphor Schwefel Chlor Argon Kalium Calcium '
    'Scandium Titan Vanadium Chrom Mangan Eisen Cobalt Nickel Kupfer Zink '
    'Gallium Germanium Arsen Selen Brom Krypton Rubidium Strontium Yttrium Zirconium '
    'Niob Molybdän Technetium Ruthenium Rhodium Palladium Silber Cadmium Indium Zinn '
    'Antimon Tellur Iod Xenon Caesium Barium Lanthan Cer Praseodym Neodym '
    'Promethium Samarium Europium Gadolinium Terbium Dysprosium Holmium Erbium Thulium Ytterbium '
    'Lutetium Hafnium Tantal Wolfram Rhenium Osmium Iridium Platin Gold Quecksilber '
    'Thallium Blei Bismut Polonium Astat Radon Francium Radium Actinium Thorium '
    'Protactinium Uran Neptunium Plutonium Americium Curium Berkelium Californium Einsteinium Fermium '
    'Mendelevium Nobelium Lawrencium Rutherfordium Dubnium Seaborgium Bohrium Hassium Meitnerium Darmstadtium '
    'Roentgenium Copernicium Nihonium Flerovium Moscovium Livermorium Tenness Oganesson'
).split()
assert len(NAMES) == 118

CATEGORIES = {
    'Nonmetal': 'Nichtmetall',
    'Noble gas': 'Edelgas',
    'Alkali metal': 'Alkalimetall',
    'Alkaline earth metal': 'Erdalkalimetall',
    'Metalloid': 'Halbmetall',
    'Halogen': 'Halogen',
    'Post-transition metal': 'Metall',
    'Transition metal': 'Übergangsmetall',
    'Lanthanide': 'Lanthanoid',
    'Actinide': 'Actinoid',
}
STATES = {'Gas': 'gasförmig', 'Liquid': 'flüssig', 'Solid': 'fest'}

# Konventionelle Atommassen (IUPAC), wie sie in Schulbüchern stehen; PubChem kürzt hier stärker.
MASS_OVERRIDES = {
    3: '6.94', 16: '32.06', 18: '39.95', 20: '40.078', 26: '55.845', 29: '63.546', 30: '65.38',
    35: '79.904', 36: '83.798', 44: '101.07', 52: '127.60', 62: '150.36', 76: '190.23', 82: '207.2',
}


def position(z: int) -> tuple[int, int]:
    """(Zeile, Spalte) im Tabellenraster, 1-basiert. f-Block in Zeile 9/10 ab Spalte 3."""
    if z == 1:
        return 1, 1
    if z == 2:
        return 1, 18
    starts = [(3, 2), (11, 3), (19, 4), (37, 5), (55, 6), (87, 7)]
    period = max(p for s, p in starts if z >= s)
    start = dict((p, s) for s, p in starts)[period]
    i = z - start  # Stelle in der Periode, 0-basiert
    if period in (2, 3):
        return period, i + 1 if i < 2 else i + 11
    if period in (4, 5):
        return period, i + 1
    # Periode 6/7: 57-71 bzw. 89-103 in die Sonderzeilen
    f_start = 57 if period == 6 else 89
    if f_start <= z <= f_start + 14:
        return (9 if period == 6 else 10), 3 + (z - f_start)
    if z < f_start:
        return period, i + 1
    return period, z - (f_start + 15) + 4


def main() -> None:
    src = Path(sys.argv[1])
    out = []
    with src.open(encoding='utf-8') as f:
        for row in csv.DictReader(f):
            z = int(row['AtomicNumber'])
            r, c = position(z)
            en = row['Electronegativity'].strip()
            out.append({
                'z': z,
                's': row['Symbol'].strip(),
                'n': NAMES[z - 1],
                'm': MASS_OVERRIDES.get(z, row['AtomicMass'].strip()),
                'en': en if en else None,
                'ec': row['ElectronConfiguration'].strip(),
                'cat': CATEGORIES.get(row['GroupBlock'].strip(), row['GroupBlock'].strip()),
                'st': STATES.get(row['StandardState'].strip(), ''),
                'r': r,
                'c': c,
            })
    assert len(out) == 118, len(out)
    target = Path(__file__).resolve().parent.parent / 'public' / 'assets' / 'aids' / 'elements.json'
    target.write_text(json.dumps(out, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf-8')
    print(f'{len(out)} Elemente nach {target}')


if __name__ == '__main__':
    main()
