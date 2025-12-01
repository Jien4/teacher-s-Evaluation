-- ===== FULL UPDATED SCHEMA + SAFE SUBJECTS & STUDENT ENROLL PATCH =====
-- Run as single batch. Safe to re-run multiple times.
CREATE DATABASE IF NOT EXISTS teacher_eval_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE teacher_eval_db;

-- CORE TABLES (idempotent)
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  fullname VARCHAR(150),
  avatar VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teachers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  course VARCHAR(100),
  year VARCHAR(16),
  description TEXT,
  email VARCHAR(150),
  avatar VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fullname VARCHAR(150) NOT NULL,
  school_id VARCHAR(50) NOT NULL UNIQUE,
  course VARCHAR(100),
  year VARCHAR(16),
  email VARCHAR(150),
  avatar VARCHAR(255) NULL,
  password VARCHAR(255) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evaluation_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_title VARCHAR(150) DEFAULT 'General',
  question_text TEXT NOT NULL,
  ordering INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evaluations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  teacher_id INT NOT NULL,
  comment TEXT,
  submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS evaluation_answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  evaluation_id INT NOT NULL,
  question_id INT NOT NULL,
  rating TINYINT NOT NULL,
  FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES evaluation_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_type ENUM('admin','student','system','teacher') DEFAULT 'system',
  user_id INT,
  action VARCHAR(255) NOT NULL,
  details TEXT,
  ip VARCHAR(45),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  token VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX token_idx (token(100)),
  INDEX student_idx (student_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NORMALIZE TEXT (best-effort)
UPDATE students SET course = UPPER(TRIM(course)), year = TRIM(year) WHERE course IS NOT NULL;
UPDATE teachers SET course = UPPER(TRIM(course)), year = TRIM(year) WHERE course IS NOT NULL;

-- SAMPLE DATA (idempotent)
INSERT INTO teachers (name, course, description, email, avatar)
SELECT 'Mr. Juan Dela Cruz','BSIT','Programming Instructor','juan@example.com','uploads/avatars/juan.jpg'
WHERE NOT EXISTS (SELECT 1 FROM teachers WHERE name='Mr. Juan Dela Cruz' LIMIT 1);

INSERT INTO teachers (name, course, description, email, avatar)
SELECT 'Ms. Maria Santos','BSBA','Business Teacher','maria@example.com','uploads/avatars/maria.jpg'
WHERE NOT EXISTS (SELECT 1 FROM teachers WHERE name='Ms. Maria Santos' LIMIT 1);

INSERT INTO evaluation_questions (question_text, ordering)
SELECT 'The teacher explains concepts clearly.',1
WHERE NOT EXISTS (SELECT 1 FROM evaluation_questions LIMIT 1);

INSERT INTO evaluation_questions (question_text, ordering)
SELECT 'The teacher is punctual and organized.',2
WHERE NOT EXISTS (SELECT 1 FROM evaluation_questions LIMIT 1);

INSERT INTO evaluation_questions (question_text, ordering)
SELECT 'The teacher provides helpful feedback.',3
WHERE NOT EXISTS (SELECT 1 FROM evaluation_questions LIMIT 1);

INSERT INTO evaluation_questions (question_text, ordering)
SELECT 'The teacher encourages participation.',4
WHERE NOT EXISTS (SELECT 1 FROM evaluation_questions LIMIT 1);

INSERT INTO evaluation_questions (question_text, ordering)
SELECT 'Overall, I am satisfied with this teacher.',5
WHERE NOT EXISTS (SELECT 1 FROM evaluation_questions LIMIT 1);

INSERT INTO admins (username, password_hash, fullname, avatar)
SELECT 'admin','$2y$10$GqyBGWhh8AlPhH6b0YItt.dtQJZPeIXrgHkPOUTVuLFGmhRKmPNAO','Default Admin','uploads/avatars/admin.png'
WHERE NOT EXISTS (SELECT 1 FROM admins WHERE username='admin' LIMIT 1);

-- SAFE: ensure unique index on evaluations (no duplicate evaluations)
SELECT COUNT(*) INTO @has_ux FROM information_schema.STATISTICS
 WHERE table_schema = DATABASE() AND table_name = 'evaluations' AND index_name = 'ux_student_teacher';

SET @sql = IF(@has_ux = 0,
  'ALTER TABLE evaluations ADD UNIQUE INDEX ux_student_teacher (student_id, teacher_id);',
  'SELECT "ux_student_teacher already exists";'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- create helpful indexes if missing
SELECT COUNT(*) INTO @has_idx_eval_student FROM information_schema.STATISTICS
 WHERE table_schema = DATABASE() AND table_name = 'evaluations' AND index_name = 'idx_eval_student';

SET @sql = IF(@has_idx_eval_student = 0,
  'CREATE INDEX idx_eval_student ON evaluations (student_id);',
  'SELECT "idx_eval_student exists";'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_idx_eval_teacher FROM information_schema.STATISTICS
 WHERE table_schema = DATABASE() AND table_name = 'evaluations' AND index_name = 'idx_eval_teacher';

SET @sql = IF(@has_idx_eval_teacher = 0,
  'CREATE INDEX idx_eval_teacher ON evaluations (teacher_id);',
  'SELECT "idx_eval_teacher exists";'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_idx_ea_question FROM information_schema.STATISTICS
 WHERE table_schema = DATABASE() AND table_name = 'evaluation_answers' AND index_name = 'idx_ea_question';

SET @sql = IF(@has_idx_ea_question = 0,
  'CREATE INDEX idx_ea_question ON evaluation_answers (question_id);',
  'SELECT "idx_ea_question exists";'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_idx_ea_evaluation FROM information_schema.STATISTICS
 WHERE table_schema = DATABASE() AND table_name = 'evaluation_answers' AND index_name = 'idx_ea_evaluation';

SET @sql = IF(@has_idx_ea_evaluation = 0,
  'CREATE INDEX idx_ea_evaluation ON evaluation_answers (evaluation_id);',
  'SELECT "idx_ea_evaluation exists";'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- SUBJECTS + TEACHER_SUBJECTS + STUDENT_SUBJECTS (safe patch)

CREATE TABLE IF NOT EXISTS subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subject_code VARCHAR(100) NOT NULL,
  subject_title VARCHAR(255) NOT NULL,
  course VARCHAR(100) NOT NULL,
  year VARCHAR(16) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_subject_code_course_year (subject_code, course, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- optional legacy handling if there's subject_name column - harmless if column doesn't exist
SELECT COUNT(*) INTO @has_subject_name_col
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE() AND table_name = 'subjects' AND column_name = 'subject_name';

SET @sql = IF(@has_subject_name_col > 0,
  CONCAT(
    "UPDATE subjects SET subject_title = COALESCE(NULLIF(subject_title, ''), subject_name) WHERE subject_name IS NOT NULL AND (subject_title IS NULL OR subject_title = '');",
    "UPDATE subjects SET subject_code = UPPER(LEFT(REPLACE(COALESCE(subject_title, subject_name, ''), ' ', '_'), 100)) WHERE (subject_code IS NULL OR subject_code = '') AND (subject_title IS NOT NULL OR subject_name IS NOT NULL);"
  ),
  "SELECT 'noop - no legacy subject_name column';"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_idx_subj_course_year FROM information_schema.STATISTICS
 WHERE table_schema = DATABASE() AND table_name = 'subjects' AND index_name = 'idx_subj_course_year';

SET @sql = IF(@has_idx_subj_course_year = 0,
  'CREATE INDEX idx_subj_course_year ON subjects (course, year);',
  'SELECT "idx_subj_course_year exists";'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS teacher_subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  subject_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_teacher_subject (teacher_id, subject_id),
  INDEX idx_ts_teacher (teacher_id),
  INDEX idx_ts_subject (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attempt to add FKs if not present (may error on some hosts; safe to ignore error)
SELECT COUNT(*) INTO @fk_count_ts FROM information_schema.TABLE_CONSTRAINTS
 WHERE table_schema = DATABASE() AND table_name = 'teacher_subjects' AND constraint_type = 'FOREIGN KEY';

SET @sql = IF(@fk_count_ts = 0,
  "ALTER TABLE teacher_subjects
     ADD CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
     ADD CONSTRAINT fk_ts_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE;",
  "SELECT 'FKs for teacher_subjects already exist or skipped';"
);
PREPARE stmt FROM @sql; 
EXECUTE stmt; 
DEALLOCATE PREPARE stmt;

-- STUDENT_SUBJECTS mapping table (for auto-enroll and dashboard)
CREATE TABLE IF NOT EXISTS student_subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_student_subject (student_id, subject_id),
  INDEX idx_ss_student (student_id),
  INDEX idx_ss_subject (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attempt to add FKs for student_subjects
SELECT COUNT(*) INTO @fk_count_ss FROM information_schema.TABLE_CONSTRAINTS
 WHERE table_schema = DATABASE() AND table_name = 'student_subjects' AND constraint_type = 'FOREIGN KEY';

SET @sql = IF(@fk_count_ss = 0,
  "ALTER TABLE student_subjects
     ADD CONSTRAINT fk_ss_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
     ADD CONSTRAINT fk_ss_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE;",
  "SELECT 'FKs for student_subjects already exist or skipped';"
);
PREPARE stmt FROM @sql; 
EXECUTE stmt; 
DEALLOCATE PREPARE stmt;

-- Insert sample subjects (idempotent)
INSERT INTO subjects (subject_code, subject_title, course, year)
SELECT 'CS101','Programming 101','BSIT','1'
WHERE NOT EXISTS (SELECT 1 FROM subjects WHERE subject_code='CS101' AND course='BSIT' AND year='1' LIMIT 1);

INSERT INTO subjects (subject_code, subject_title, course, year)
SELECT 'BUS101','Business Foundations','BSBA','1'
WHERE NOT EXISTS (SELECT 1 FROM subjects WHERE subject_code='BUS101' AND course='BSBA' AND year='1' LIMIT 1);

-- Ensure mapping: assign sample teachers to sample subjects (idempotent)
INSERT INTO teacher_subjects (teacher_id, subject_id)
SELECT t.id, s.id
FROM teachers t
JOIN subjects s ON UPPER(TRIM(s.course)) = UPPER(TRIM(t.course)) AND TRIM(s.year) = TRIM(t.year)
WHERE t.name LIKE '%Juan Dela Cruz%' AND s.subject_code = 'CS101'
  AND NOT EXISTS (SELECT 1 FROM teacher_subjects ts WHERE ts.teacher_id = t.id AND ts.subject_id = s.id)
LIMIT 1;

INSERT INTO teacher_subjects (teacher_id, subject_id)
SELECT t.id, s.id
FROM teachers t
JOIN subjects s ON UPPER(TRIM(s.course)) = UPPER(TRIM(t.course)) AND TRIM(s.year) = TRIM(t.year)
WHERE t.name LIKE '%Maria Santos%' AND s.subject_code = 'BUS101'
  AND NOT EXISTS (SELECT 1 FROM teacher_subjects ts WHERE ts.teacher_id = t.id AND ts.subject_id = s.id)
LIMIT 1;

-- End of patch
