-- Klausurwerk: Grundschema.
-- Neue Spalten/Tabellen kommen als weitere, nummerierte Migration dazu;
-- diese Datei wird nach dem ersten Release nicht mehr verändert.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Benutzer & Rechte
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(255) NULL,
    password VARCHAR(255) NULL,
    firstname VARCHAR(100) NOT NULL DEFAULT '',
    lastname VARCHAR(100) NOT NULL DEFAULT '',
    role ENUM('admin','teacher','student') NOT NULL DEFAULT 'student',
    created_via ENUM('manual','import') NOT NULL DEFAULT 'manual',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    -- Zugangskarte: wird bei jedem Neudruck/Zurücksetzen erhöht.
    card_version INT UNSIGNED NOT NULL DEFAULT 0,
    card_issued_at DATETIME NULL,
    -- Nachteilsausgleich in Prozent Zusatzzeit (z. B. 25 = +25 %).
    accommodation_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    accommodation_note VARCHAR(255) NULL,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role),
    KEY idx_users_name (lastname, firstname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    permission VARCHAR(100) NOT NULL,
    granted_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_permissions (user_id, permission),
    CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permissions_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permission_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permission_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permission_group_items (
    group_id INT UNSIGNED NOT NULL,
    permission VARCHAR(100) NOT NULL,
    PRIMARY KEY (group_id, permission),
    CONSTRAINT fk_pgi_group FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permission_groups (
    user_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    granted_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, group_id),
    CONSTRAINT fk_upg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_upg_group FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_upg_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Notenschlüssel & Operatoren
-- ---------------------------------------------------------------------------
-- thresholds: JSON-Liste absteigend nach min_percent:
--   [{"label":"15","value":15,"min_percent":95}, …]  (type = points)
--   [{"label":"1+","value":1.0,"min_percent":96}, …] (type = grades)
CREATE TABLE IF NOT EXISTS grading_scales (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    type ENUM('grades','points') NOT NULL,
    -- Standard für diese Klassenstufe (NULL = frei wählbare Vorlage).
    grade_level TINYINT UNSIGNED NULL,
    thresholds JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grading_scales_level (grade_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operators (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,
    -- 'I', 'II', 'III', 'I-II', 'II-III'
    afb VARCHAR(10) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    sort_order SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_operators_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Kurse
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS courses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    grade_level TINYINT UNSIGNED NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    subject VARCHAR(60) NOT NULL DEFAULT 'Informatik',
    -- Abweichender Notenschlüssel (NULL = Standard der Klassenstufe).
    grading_scale_id INT UNSIGNED NULL,
    owner_id INT UNSIGNED NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_courses_name_year (name, school_year),
    KEY idx_courses_owner (owner_id),
    CONSTRAINT fk_courses_scale FOREIGN KEY (grading_scale_id) REFERENCES grading_scales(id) ON DELETE SET NULL,
    CONSTRAINT fk_courses_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weitere Lehrkräfte eines Kurses (Vertretung, Team-Teaching).
CREATE TABLE IF NOT EXISTS course_teachers (
    course_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (course_id, user_id),
    CONSTRAINT fk_ct_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_ct_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_members (
    course_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (course_id, user_id),
    KEY idx_cm_user (user_id),
    CONSTRAINT fk_cm_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Räume & Sitzplan
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    -- Rastergröße des Sitzplans (Spalten x Zeilen).
    grid_cols TINYINT UNSIGNED NOT NULL DEFAULT 8,
    grid_rows TINYINT UNSIGNED NOT NULL DEFAULT 6,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rooms_name (name),
    CONSTRAINT fk_rooms_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platz einer Person in einem Raum (bleibt über Klausuren hinweg erhalten).
CREATE TABLE IF NOT EXISTS room_positions (
    room_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    col TINYINT UNSIGNED NOT NULL,
    `row` TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (room_id, user_id),
    UNIQUE KEY uq_room_positions_cell (room_id, col, `row`),
    CONSTRAINT fk_rp_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Klausuren
-- ---------------------------------------------------------------------------
-- status:
--   draft → ready → lobby → running ⇄ paused → finished → grading → returned → archived
-- mode: exam (Klausur), practice (Übung, ohne Überwachung), probe (Technik-Check)
-- settings: JSON, Schema siehe docs/DATENFORMATE.md („Klausur-Einstellungen“)
CREATE TABLE IF NOT EXISTS exams (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    course_id INT UNSIGNED NULL,
    owner_id INT UNSIGNED NULL,
    room_id INT UNSIGNED NULL,
    mode ENUM('exam','practice','probe') NOT NULL DEFAULT 'exam',
    status ENUM('draft','ready','lobby','running','paused','finished','grading','returned','archived') NOT NULL DEFAULT 'draft',
    duration_minutes SMALLINT UNSIGNED NULL,
    scheduled_at DATETIME NULL,
    started_at DATETIME(3) NULL,
    -- Gesamtpause (alle): laufende Pause seit paused_at, abgeschlossene in paused_seconds.
    paused_at DATETIME(3) NULL,
    paused_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    time_up_notified_at DATETIME(3) NULL,
    finished_at DATETIME(3) NULL,
    returned_at DATETIME NULL,
    access_code VARCHAR(12) NULL,
    settings JSON NOT NULL,
    -- SEB: Config-Key (hex, SHA-256) der erzeugten .seb-Datei.
    seb_config_key CHAR(64) NULL,
    seb_quit_password_hash CHAR(64) NULL,
    -- Klausur, aus der kopiert wurde (Archiv-Wiederverwendung).
    copied_from_exam_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_exams_course (course_id),
    KEY idx_exams_owner (owner_id),
    KEY idx_exams_status (status),
    CONSTRAINT fk_exams_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    CONSTRAINT fk_exams_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_exams_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    CONSTRAINT fk_exams_copied FOREIGN KEY (copied_from_exam_id) REFERENCES exams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weitere Lehrkräfte einer Klausur (Aufsicht). Pult + Mitlesen immer,
-- Lösungen/Korrektur nur mit Schalter der Hauptlehrkraft.
CREATE TABLE IF NOT EXISTS exam_staff (
    exam_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    can_view_solutions TINYINT(1) NOT NULL DEFAULT 0,
    can_grade TINYINT(1) NOT NULL DEFAULT 0,
    added_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (exam_id, user_id),
    KEY idx_exam_staff_user (user_id),
    CONSTRAINT fk_es_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_es_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_es_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aufgaben und Teilaufgaben (parent_id). Nur schülersichtige Daten!
-- Lösungen, Erwartungshorizont und Tests liegen in task_solutions.
-- answer_type NULL = reine Überschrift/Container mit Teilaufgaben.
-- config: JSON, Schema je answer_type siehe docs/DATENFORMATE.md
CREATE TABLE IF NOT EXISTS tasks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    title VARCHAR(200) NOT NULL DEFAULT '',
    body_md MEDIUMTEXT NOT NULL,
    points DECIMAL(6,2) NOT NULL DEFAULT 0,
    operator_id INT UNSIGNED NULL,
    afb VARCHAR(10) NULL,
    answer_type VARCHAR(30) NULL,
    config JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tasks_exam (exam_id, parent_id, position),
    FULLTEXT KEY ft_tasks_text (title, body_md),
    CONSTRAINT fk_tasks_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_parent FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_operator FOREIGN KEY (operator_id) REFERENCES operators(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NIE an Schüler:innen ausliefern (siehe ARCHITECTURE.md, Sicherheits-Pflichten).
CREATE TABLE IF NOT EXISTS task_solutions (
    task_id INT UNSIGNED NOT NULL,
    -- Automatisch prüfbare Lösung je Antworttyp (richtige Optionen, Lücken, …).
    solution JSON NOT NULL,
    -- Musterlösung (Code/Text) und Erwartungshorizont als Markdown.
    model_answer MEDIUMTEXT NOT NULL,
    expectation_md MEDIUMTEXT NOT NULL,
    -- Versteckte Tests (Code-Aufgaben), siehe docs/DATENFORMATE.md.
    tests JSON NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id),
    CONSTRAINT fk_ts_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vorgegebene Textdateien einer Code-Aufgabe (Klassen, CSV, …).
CREATE TABLE IF NOT EXISTS task_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    filename VARCHAR(200) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    is_readonly TINYINT(1) NOT NULL DEFAULT 0,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_task_files_name (task_id, filename),
    CONSTRAINT fk_tf_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Materialien (Bilder, PDFs) einer Klausur; Auslieferung nur über geschützte Route.
-- is_aid = 1: erscheint als Hilfsmittel in der Seitenleiste.
CREATE TABLE IF NOT EXISTS exam_materials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id INT UNSIGNED NOT NULL,
    task_id INT UNSIGNED NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(100) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    is_aid TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exam_materials_stored (stored_name),
    KEY idx_exam_materials_exam (exam_id),
    CONSTRAINT fk_em_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_em_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Teilnahme, Antworten, Überwachung
-- ---------------------------------------------------------------------------
-- state: siehe Services\ParticipationState (einzige Stelle für Übergänge)
CREATE TABLE IF NOT EXISTS participations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    state ENUM('waiting','active','locked','absent','paused','submit_requested','submitted','device_pending') NOT NULL DEFAULT 'waiting',
    state_changed_at DATETIME(3) NULL,
    -- Zustand vor einer Sperre/Abwesenheit/Pause (für die Rückkehr).
    resume_state VARCHAR(20) NULL,
    lock_reason VARCHAR(255) NULL,
    -- Gerätebindung: SHA-256 des Geräte-Tokens (Cookie).
    device_hash CHAR(64) NULL,
    pending_device_hash CHAR(64) NULL,
    joined_at DATETIME(3) NULL,
    ready_at DATETIME(3) NULL,
    -- Zeit: Zusatzminuten (Pult) und Nachteilsausgleich (Kopie beim Beitritt).
    extra_minutes SMALLINT NOT NULL DEFAULT 0,
    accommodation_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- Einzelpause (Pause einzeln oder Abwesenheit mit angehaltener Uhr).
    paused_at DATETIME(3) NULL,
    paused_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    time_up_notified_at DATETIME(3) NULL,
    submit_requested_at DATETIME(3) NULL,
    submitted_at DATETIME(3) NULL,
    submitted_by INT UNSIGNED NULL,
    -- Höchste vom Server bestätigte Client-Sequenznummer.
    last_seq INT UNSIGNED NOT NULL DEFAULT 0,
    last_seen_at DATETIME(3) NULL,
    -- Letzter Client-Zustand (Vollbild, Fokus, SEB, Bildschirm, …).
    client_state JSON NULL,
    hand_raised_at DATETIME(3) NULL,
    shuffle_seed INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_participations (exam_id, user_id),
    KEY idx_participations_user (user_id),
    CONSTRAINT fk_part_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_part_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_part_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aktueller Stand je Teilnahme und Aufgabe. content: JSON je answer_type.
CREATE TABLE IF NOT EXISTS answers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    participation_id INT UNSIGNED NOT NULL,
    task_id INT UNSIGNED NOT NULL,
    content MEDIUMTEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    flagged TINYINT(1) NOT NULL DEFAULT 0,
    -- Mindestens eine Änderung nach Zeitende.
    has_overtime TINYINT(1) NOT NULL DEFAULT 0,
    -- PNG-Vorschau (Data-URL) für Zeichenaufgaben, für Korrektur/PDF.
    preview_png MEDIUMTEXT NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_answers (participation_id, task_id),
    CONSTRAINT fk_answers_part FOREIGN KEY (participation_id) REFERENCES participations(id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Änderungsschritte für das Replay. seq ist je Teilnahme eindeutig (Client-Zähler).
CREATE TABLE IF NOT EXISTS answer_ops (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    participation_id INT UNSIGNED NOT NULL,
    task_id INT UNSIGNED NOT NULL,
    seq INT UNSIGNED NOT NULL,
    client_ts BIGINT UNSIGNED NOT NULL,
    server_ts DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    -- 'ops' (Editor-Changeset), 'snapshot' (Volltext), 'value' (strukturierte Antwort)
    kind VARCHAR(10) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    is_paste TINYINT(1) NOT NULL DEFAULT 0,
    is_overtime TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_answer_ops_seq (participation_id, seq),
    KEY idx_answer_ops_task (participation_id, task_id, seq),
    CONSTRAINT fk_ao_part FOREIGN KEY (participation_id) REFERENCES participations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ao_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ereignisse & Zustandswechsel. exam_id denormalisiert für den Pult-Cursor.
CREATE TABLE IF NOT EXISTS participation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id INT UNSIGNED NOT NULL,
    participation_id INT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL,
    severity ENUM('info','warning','alarm') NOT NULL DEFAULT 'info',
    details JSON NULL,
    actor_id INT UNSIGNED NULL,
    client_ts BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_pe_exam (exam_id, id),
    KEY idx_pe_part (participation_id, id),
    CONSTRAINT fk_pe_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_pe_part FOREIGN KEY (participation_id) REFERENCES participations(id) ON DELETE CASCADE,
    CONSTRAINT fk_pe_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Durchsagen. participation_id NULL = an alle.
CREATE TABLE IF NOT EXISTS exam_announcements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id INT UNSIGNED NOT NULL,
    participation_id INT UNSIGNED NULL,
    message TEXT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_ea_exam (exam_id, id),
    CONSTRAINT fk_ea_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_ea_part FOREIGN KEY (participation_id) REFERENCES participations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ea_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anfragen aus der Schüleransicht an das Pult.
CREATE TABLE IF NOT EXISTS exam_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id INT UNSIGNED NOT NULL,
    participation_id INT UNSIGNED NOT NULL,
    type ENUM('hand','release','submit','device') NOT NULL,
    status ENUM('open','approved','rejected','cancelled') NOT NULL DEFAULT 'open',
    details JSON NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    resolved_at DATETIME(3) NULL,
    resolved_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_er_exam (exam_id, status),
    KEY idx_er_part (participation_id, type, status),
    CONSTRAINT fk_er_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_er_part FOREIGN KEY (participation_id) REFERENCES participations(id) ON DELETE CASCADE,
    CONSTRAINT fk_er_resolved_by FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hochgeladene Dateien einer Antwort (Antworttyp „drawing“ mit Upload).
CREATE TABLE IF NOT EXISTS answer_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    participation_id INT UNSIGNED NOT NULL,
    task_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(100) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_answer_files_stored (stored_name),
    KEY idx_af_part (participation_id, task_id),
    CONSTRAINT fk_af_part FOREIGN KEY (participation_id) REFERENCES participations(id) ON DELETE CASCADE,
    CONSTRAINT fk_af_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Korrektur
-- ---------------------------------------------------------------------------
-- Bewertung je Aufgabe und korrigierender Lehrkraft (Erst-/Zweitkorrektur).
CREATE TABLE IF NOT EXISTS grading_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    participation_id INT UNSIGNED NOT NULL,
    task_id INT UNSIGNED NOT NULL,
    grader_id INT UNSIGNED NOT NULL,
    points DECIMAL(6,2) NULL,
    comment TEXT NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_grading_entries (participation_id, task_id, grader_id),
    CONSTRAINT fk_ge_part FOREIGN KEY (participation_id) REFERENCES participations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ge_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_ge_grader FOREIGN KEY (grader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Automatische Vorbewertung (MC, Lücken, Tests) je Aufgabe.
CREATE TABLE IF NOT EXISTS auto_gradings (
    participation_id INT UNSIGNED NOT NULL,
    task_id INT UNSIGNED NOT NULL,
    suggested_points DECIMAL(6,2) NULL,
    details JSON NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (participation_id, task_id),
    CONSTRAINT fk_ag_part FOREIGN KEY (participation_id) REFERENCES participations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ag_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anmerkungen an Code-Zeilen oder Textstellen. anchor: JSON siehe DATENFORMATE.md
CREATE TABLE IF NOT EXISTS grading_comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    participation_id INT UNSIGNED NOT NULL,
    task_id INT UNSIGNED NOT NULL,
    grader_id INT UNSIGNED NOT NULL,
    anchor JSON NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_gc_part (participation_id, task_id),
    CONSTRAINT fk_gc_part FOREIGN KEY (participation_id) REFERENCES participations(id) ON DELETE CASCADE,
    CONSTRAINT fk_gc_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_gc_grader FOREIGN KEY (grader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gesamtergebnis je Teilnahme.
CREATE TABLE IF NOT EXISTS participation_results (
    participation_id INT UNSIGNED NOT NULL,
    total_points DECIMAL(7,2) NULL,
    max_points DECIMAL(7,2) NULL,
    percent DECIMAL(5,2) NULL,
    grade_label VARCHAR(10) NULL,
    general_comment TEXT NULL,
    finalized_at DATETIME NULL,
    finalized_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (participation_id),
    CONSTRAINT fk_pr_part FOREIGN KEY (participation_id) REFERENCES participations(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_finalized_by FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Einstellungen, Audit, Login-Schutz
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    username VARCHAR(100) NULL,
    action VARCHAR(100) NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_created (created_at),
    KEY idx_audit_logs_action (action),
    KEY idx_audit_logs_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_username (username, attempted_at),
    KEY idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_codes_user (user_id, created_at),
    CONSTRAINT fk_login_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    template VARCHAR(60) NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_email_log_user (user_id),
    CONSTRAINT fk_email_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
