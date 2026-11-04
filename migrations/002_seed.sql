-- Startbestand: Notenschlüssel je Klassenstufe und Operatorenliste.
-- Beides ist im Admin-Bereich änderbar.

SET NAMES utf8mb4;

-- Klasse 10 (Einführungsphase): Noten mit Tendenzen.
INSERT INTO grading_scales (name, type, grade_level, thresholds) VALUES
('Noten 1 bis 6 (Klasse 10)', 'grades', 10, JSON_ARRAY(
    JSON_OBJECT('label','1+','value',0.7,'min_percent',95),
    JSON_OBJECT('label','1','value',1.0,'min_percent',90),
    JSON_OBJECT('label','1-','value',1.3,'min_percent',85),
    JSON_OBJECT('label','2+','value',1.7,'min_percent',80),
    JSON_OBJECT('label','2','value',2.0,'min_percent',75),
    JSON_OBJECT('label','2-','value',2.3,'min_percent',70),
    JSON_OBJECT('label','3+','value',2.7,'min_percent',65),
    JSON_OBJECT('label','3','value',3.0,'min_percent',60),
    JSON_OBJECT('label','3-','value',3.3,'min_percent',55),
    JSON_OBJECT('label','4+','value',3.7,'min_percent',50),
    JSON_OBJECT('label','4','value',4.0,'min_percent',45),
    JSON_OBJECT('label','4-','value',4.3,'min_percent',40),
    JSON_OBJECT('label','5+','value',4.7,'min_percent',33),
    JSON_OBJECT('label','5','value',5.0,'min_percent',27),
    JSON_OBJECT('label','5-','value',5.3,'min_percent',20),
    JSON_OBJECT('label','6','value',6.0,'min_percent',0)
)),
('Punkte 0 bis 15 (Klasse 11)', 'points', 11, JSON_ARRAY(
    JSON_OBJECT('label','15','value',15,'min_percent',95),
    JSON_OBJECT('label','14','value',14,'min_percent',90),
    JSON_OBJECT('label','13','value',13,'min_percent',85),
    JSON_OBJECT('label','12','value',12,'min_percent',80),
    JSON_OBJECT('label','11','value',11,'min_percent',75),
    JSON_OBJECT('label','10','value',10,'min_percent',70),
    JSON_OBJECT('label','09','value',9,'min_percent',65),
    JSON_OBJECT('label','08','value',8,'min_percent',60),
    JSON_OBJECT('label','07','value',7,'min_percent',55),
    JSON_OBJECT('label','06','value',6,'min_percent',50),
    JSON_OBJECT('label','05','value',5,'min_percent',45),
    JSON_OBJECT('label','04','value',4,'min_percent',40),
    JSON_OBJECT('label','03','value',3,'min_percent',33),
    JSON_OBJECT('label','02','value',2,'min_percent',27),
    JSON_OBJECT('label','01','value',1,'min_percent',20),
    JSON_OBJECT('label','00','value',0,'min_percent',0)
)),
('Punkte 0 bis 15 (Klasse 12)', 'points', 12, JSON_ARRAY(
    JSON_OBJECT('label','15','value',15,'min_percent',95),
    JSON_OBJECT('label','14','value',14,'min_percent',90),
    JSON_OBJECT('label','13','value',13,'min_percent',85),
    JSON_OBJECT('label','12','value',12,'min_percent',80),
    JSON_OBJECT('label','11','value',11,'min_percent',75),
    JSON_OBJECT('label','10','value',10,'min_percent',70),
    JSON_OBJECT('label','09','value',9,'min_percent',65),
    JSON_OBJECT('label','08','value',8,'min_percent',60),
    JSON_OBJECT('label','07','value',7,'min_percent',55),
    JSON_OBJECT('label','06','value',6,'min_percent',50),
    JSON_OBJECT('label','05','value',5,'min_percent',45),
    JSON_OBJECT('label','04','value',4,'min_percent',40),
    JSON_OBJECT('label','03','value',3,'min_percent',33),
    JSON_OBJECT('label','02','value',2,'min_percent',27),
    JSON_OBJECT('label','01','value',1,'min_percent',20),
    JSON_OBJECT('label','00','value',0,'min_percent',0)
));

INSERT INTO operators (name, afb, description, sort_order) VALUES
('nennen', 'I', 'Elemente, Sachverhalte, Begriffe oder Daten ohne Erläuterung aufzählen.', 10),
('angeben', 'I', 'Elemente, Sachverhalte, Begriffe oder Daten ohne Erläuterung benennen.', 20),
('wiedergeben', 'I', 'Bekannte Inhalte mit eigenen Worten ausdrücken.', 30),
('beschreiben', 'I-II', 'Sachverhalte strukturiert und fachsprachlich richtig mit eigenen Worten darlegen.', 40),
('darstellen', 'I-II', 'Sachverhalte, Strukturen oder Zusammenhänge in geeigneter Form (Text, Diagramm, Tabelle) wiedergeben.', 50),
('skizzieren', 'I-II', 'Sachverhalte, Strukturen oder Ergebnisse auf das Wesentliche reduziert übersichtlich darstellen.', 60),
('zeichnen', 'I-II', 'Eine grafische Darstellung (z. B. Diagramm, Struktogramm) anfertigen.', 70),
('erläutern', 'II', 'Sachverhalte so darlegen und veranschaulichen, dass Bedingungen, Ursachen und Wirkungen verständlich werden.', 80),
('erklären', 'II', 'Sachverhalte auf Regeln, Gesetzmäßigkeiten oder Ursachen zurückführen.', 90),
('ermitteln', 'II', 'Ein Ergebnis oder einen Zusammenhang rechnerisch, grafisch oder durch Ablauf eines Algorithmus gewinnen.', 100),
('bestimmen', 'II', 'Einen Lösungsweg darstellen und das Ergebnis formulieren.', 110),
('anwenden', 'II', 'Einen bekannten Sachverhalt oder eine bekannte Methode auf etwas Neues beziehen.', 120),
('vergleichen', 'II', 'Gemeinsamkeiten, Ähnlichkeiten und Unterschiede kriteriengeleitet herausarbeiten.', 130),
('untersuchen', 'II', 'Sachverhalte nach bestimmten Kriterien betrachten und Ergebnisse darstellen.', 140),
('dokumentieren', 'II', 'Entscheidende Erklärungen, Herleitungen und Skizzen zu einem Programm oder Ablauf darstellen.', 150),
('implementieren', 'II-III', 'Einen Algorithmus oder eine Modellierung in einer Programmiersprache umsetzen.', 160),
('modellieren', 'II-III', 'Zu einem Sachverhalt ein informatisches Modell (z. B. Klassen-, ER-Diagramm) erstellen.', 170),
('entwerfen', 'II-III', 'Ein Konzept in seinen wesentlichen Zügen prospektiv planen und darstellen.', 180),
('analysieren', 'II-III', 'Wichtige Bestandteile, Eigenschaften oder Abläufe auf eine bestimmte Fragestellung hin herausarbeiten.', 190),
('begründen', 'II-III', 'Einen angegebenen Sachverhalt auf Gesetzmäßigkeiten oder kausale Zusammenhänge zurückführen.', 200),
('entwickeln', 'III', 'Eine Lösung, ein Modell oder einen Algorithmus zielgerichtet und eigenständig erarbeiten.', 210),
('überprüfen', 'III', 'Sachverhalte oder Aussagen an Fakten oder innerer Logik messen und Widersprüche aufdecken.', 220),
('beurteilen', 'III', 'Zu einem Sachverhalt ein selbstständiges Urteil unter Verwendung von Fachwissen formulieren und begründen.', 230),
('bewerten', 'III', 'Eine eigene Position nach ausgewiesenen Normen und Werten vertreten.', 240),
('interpretieren', 'III', 'Phänomene, Strukturen oder Ergebnisse auf Erklärungsmöglichkeiten untersuchen und abwägend herausstellen.', 250);
