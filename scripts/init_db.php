<?php
declare(strict_types=1);

/**
 * EduSuite PHP - Init DB (SQLite)
 * Crea el esquema MVP completo en storage/edusuite.sqlite
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
  fwrite(STDERR, "❌ No se pudo resolver la ruta del proyecto\n");
  exit(1);
}

$storageDir = $root . DIRECTORY_SEPARATOR . 'storage';
$dbPath     = $storageDir . DIRECTORY_SEPARATOR . 'edusuite.sqlite';

@mkdir($storageDir, 0775, true);
@mkdir($storageDir . DIRECTORY_SEPARATOR . 'uploads', 0775, true);
@mkdir($storageDir . DIRECTORY_SEPARATOR . 'exports', 0775, true);

try {
  $pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // Recomendado en SQLite
  $pdo->exec('PRAGMA foreign_keys = ON;');
  $pdo->exec('PRAGMA journal_mode = WAL;');

  $sql = <<<SQL
BEGIN;

-- =========================
-- USERS / AUTH
-- =========================
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  pass_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK(role IN ('docente','alumno')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- =========================
-- LMS: Courses / Modules / Lessons / Materials
-- =========================
CREATE TABLE IF NOT EXISTS courses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  is_published INTEGER NOT NULL DEFAULT 0,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS modules (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  course_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  position INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS lessons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  module_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  content_html TEXT NOT NULL DEFAULT '',
  position INTEGER NOT NULL DEFAULT 0,
  is_visible INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS materials (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lesson_id INTEGER NOT NULL,
  kind TEXT NOT NULL CHECK(kind IN ('file','link','text')),
  title TEXT NOT NULL,
  url TEXT,
  file_path TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
);

-- =========================
-- Enrollments + Progress
-- =========================
CREATE TABLE IF NOT EXISTS enrollments (
  user_id INTEGER NOT NULL,
  course_id INTEGER NOT NULL,
  enrolled_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (user_id, course_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS lesson_progress (
  user_id INTEGER NOT NULL,
  lesson_id INTEGER NOT NULL,
  completed_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (user_id, lesson_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
);

-- =========================
-- Quizzes: quiz -> questions -> options + attempts/answers
-- =========================
CREATE TABLE IF NOT EXISTS quizzes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scope TEXT NOT NULL CHECK(scope IN ('lesson','module','course')),
  scope_id INTEGER NOT NULL, -- id de lesson/module/course según scope
  title TEXT NOT NULL,
  pass_score INTEGER NOT NULL DEFAULT 60, -- %
  time_limit_min INTEGER, -- null = sin límite
  shuffle_questions INTEGER NOT NULL DEFAULT 1,
  shuffle_options INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS questions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  text TEXT NOT NULL,
  type TEXT NOT NULL CHECK(type IN ('single','multi','truefalse')),
  difficulty INTEGER NOT NULL DEFAULT 2 CHECK(difficulty BETWEEN 1 AND 5),
  topic TEXT NOT NULL DEFAULT '',
  competence TEXT NOT NULL DEFAULT '',
  tags TEXT NOT NULL DEFAULT '', -- CSV simple: "redes,seguridad,windows"
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- Relación quiz->questions (para reusar preguntas)
CREATE TABLE IF NOT EXISTS quiz_questions (
  quiz_id INTEGER NOT NULL,
  question_id INTEGER NOT NULL,
  position INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (quiz_id, question_id),
  FOREIGN KEY(quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
  FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS options (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  question_id INTEGER NOT NULL,
  label TEXT NOT NULL, -- "A", "B", etc. (opcional)
  text TEXT NOT NULL,
  is_correct INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  quiz_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  started_at TEXT NOT NULL DEFAULT (datetime('now')),
  finished_at TEXT,
  score_percent INTEGER, -- null hasta terminar
  passed INTEGER, -- 0/1
  FOREIGN KEY(quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS quiz_answers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id INTEGER NOT NULL,
  question_id INTEGER NOT NULL,
  selected_option_ids TEXT NOT NULL DEFAULT '', -- CSV "12,15"
  is_correct INTEGER, -- null hasta corregir
  FOREIGN KEY(attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
  FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE
);

-- =========================
-- Assignments (tareas) + submissions
-- =========================
CREATE TABLE IF NOT EXISTS assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scope TEXT NOT NULL CHECK(scope IN ('lesson','module','course')),
  scope_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  due_date TEXT, -- ISO string o null
  max_score INTEGER NOT NULL DEFAULT 100,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS submissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  assignment_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  comment TEXT NOT NULL DEFAULT '',
  file_path TEXT,
  submitted_at TEXT NOT NULL DEFAULT (datetime('now')),
  grade INTEGER,
  feedback TEXT NOT NULL DEFAULT '',
  graded_at TEXT,
  FOREIGN KEY(assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =========================
-- Certificates
-- =========================
CREATE TABLE IF NOT EXISTS certificates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  course_id INTEGER NOT NULL,
  issued_at TEXT NOT NULL DEFAULT (datetime('now')),
  pdf_path TEXT, -- ruta en storage/exports
  verification_code TEXT NOT NULL UNIQUE, -- para validar online
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
);

-- =========================
-- Exam generator A/B/C
-- =========================
CREATE TABLE IF NOT EXISTS exam_generations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  course_id INTEGER,
  module_id INTEGER,
  title TEXT NOT NULL,
  num_questions INTEGER NOT NULL DEFAULT 20,
  difficulty_min INTEGER NOT NULL DEFAULT 1,
  difficulty_max INTEGER NOT NULL DEFAULT 5,
  topic_filter TEXT NOT NULL DEFAULT '',
  competence_filter TEXT NOT NULL DEFAULT '',
  tags_filter TEXT NOT NULL DEFAULT '',
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE SET NULL,
  FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE SET NULL,
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS exam_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  exam_id INTEGER NOT NULL,
  version TEXT NOT NULL CHECK(version IN ('A','B','C')),
  question_id INTEGER NOT NULL,
  position INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY(exam_id) REFERENCES exam_generations(id) ON DELETE CASCADE,
  FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE CASCADE
);

-- =========================
-- Labs (simulador) + steps + submissions
-- =========================
CREATE TABLE IF NOT EXISTS labs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT '', -- phishing, backup, permisos...
  points_total INTEGER NOT NULL DEFAULT 100,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS lab_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lab_id INTEGER NOT NULL,
  position INTEGER NOT NULL DEFAULT 0,
  instruction TEXT NOT NULL,
  expected_evidence TEXT NOT NULL DEFAULT '',
  points INTEGER NOT NULL DEFAULT 10,
  FOREIGN KEY(lab_id) REFERENCES labs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS lab_submissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lab_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  comment TEXT NOT NULL DEFAULT '',
  evidence_paths TEXT NOT NULL DEFAULT '', -- CSV de ficheros
  score INTEGER,
  feedback TEXT NOT NULL DEFAULT '',
  submitted_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(lab_id) REFERENCES labs(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =========================
-- Portfolio + evidencias
-- =========================
CREATE TABLE IF NOT EXISTS portfolios (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  reflection TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS portfolio_evidences (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  portfolio_id INTEGER NOT NULL,
  kind TEXT NOT NULL CHECK(kind IN ('file','link','text')),
  title TEXT NOT NULL,
  value TEXT NOT NULL, -- url o texto o file_path
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(portfolio_id) REFERENCES portfolios(id) ON DELETE CASCADE
);

-- =========================
-- Badges + user_badges
-- =========================
CREATE TABLE IF NOT EXISTS badges (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  icon TEXT NOT NULL DEFAULT '', -- nombre icono o ruta
  category TEXT NOT NULL DEFAULT '', -- Office/Linux/Redes/Ciber
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS user_badges (
  user_id INTEGER NOT NULL,
  badge_id INTEGER NOT NULL,
  awarded_at TEXT NOT NULL DEFAULT (datetime('now')),
  note TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (user_id, badge_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(badge_id) REFERENCES badges(id) ON DELETE CASCADE
);

-- =========================
-- Markdown docs (dispensas) + exports
-- =========================
CREATE TABLE IF NOT EXISTS markdown_docs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  markdown TEXT NOT NULL,
  variant TEXT NOT NULL CHECK(variant IN ('alumno','docente')),
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS exports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL CHECK(kind IN ('dispensa_pdf','portfolio_pdf','certificado_pdf','examen_pdf')),
  ref_table TEXT NOT NULL DEFAULT '', -- "markdown_docs", "portfolios", etc.
  ref_id INTEGER,
  file_path TEXT NOT NULL,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
);

-- =========================
-- Índices (mejor rendimiento)
-- =========================
CREATE INDEX IF NOT EXISTS idx_modules_course ON modules(course_id);
CREATE INDEX IF NOT EXISTS idx_lessons_module ON lessons(module_id);
CREATE INDEX IF NOT EXISTS idx_materials_lesson ON materials(lesson_id);
CREATE INDEX IF NOT EXISTS idx_progress_user ON lesson_progress(user_id);
CREATE INDEX IF NOT EXISTS idx_enroll_course ON enrollments(course_id);
CREATE INDEX IF NOT EXISTS idx_quiz_attempts_user ON quiz_attempts(user_id);
CREATE INDEX IF NOT EXISTS idx_exam_items_exam ON exam_items(exam_id);
CREATE INDEX IF NOT EXISTS idx_lab_steps_lab ON lab_steps(lab_id);
CREATE INDEX IF NOT EXISTS idx_portfolio_user ON portfolios(user_id);

COMMIT;
SQL;

  $pdo->exec($sql);

  echo "✅ OK: BD creada/actualizada en: {$dbPath}\n";
  echo "📁 Carpetas listas: storage/uploads y storage/exports\n";

} catch (Throwable $e) {
  fwrite(STDERR, "❌ ERROR: " . $e->getMessage() . "\n");
  exit(1);
}
