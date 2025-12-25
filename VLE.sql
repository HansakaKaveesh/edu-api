vlevlevle-- Authentication & Users
 CREATE TABLE users (
  user_id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(100) UNIQUE NOT NULL,
  role ENUM('student', 'teacher', 'admin', 'ceo', 'cto','accountant','coordinator') NOT NULL,
  status ENUM('active','inactive','suspended') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 );
 CREATE TABLE passwords (
  password_id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  is_current BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (user_id) REFERENCES users(user_id)
 );-- Profiles
 CREATE TABLE students (
  student_id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT UNIQUE NOT NULL,
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  dob DATE,
  contact_number VARCHAR(15),
  email VARCHAR(100),
  registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id)
 );
 CREATE TABLE teachers (
  teacher_id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT UNIQUE NOT NULL,
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  email VARCHAR(100),
  FOREIGN KEY (user_id) REFERENCES users(user_id)
 );
 CREATE TABLE accountants (
  accountant_id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT UNIQUE NOT NULL,
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  email VARCHAR(100),
  contact_number VARCHAR(15),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);
 -- Course structure
 CREATE TABLE course_types (
  course_type_id INT PRIMARY KEY AUTO_INCREMENT,
  board ENUM('Cambridge','Edexcel','Local','Other') NOT NULL,
  level ENUM('O/L','A/L','IGCSE','Others') NOT NULL,
  description VARCHAR(255)
 );
 CREATE TABLE courses (
  course_id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(100),
  description TEXT,
  course_type_id INT NOT NULL,
  FOREIGN KEY (course_type_id) REFERENCES course_types(course_type_id)
 );
 
 ALTER TABLE courses
  ADD COLUMN cover_image VARCHAR(255) NULL AFTER price;
 
 CREATE TABLE teacher_courses (
  teacher_id INT NOT NULL,
  course_id INT NOT NULL,
  PRIMARY KEY (teacher_id, course_id),
  FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id),
  FOREIGN KEY (course_id) REFERENCES courses(course_id)
 );
 CREATE TABLE enrollments (
  enrollment_id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  course_id INT NOT NULL,
  status ENUM('active','completed','dropped') DEFAULT 'active',
  enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id),
  FOREIGN KEY (course_id) REFERENCES courses(course_id)
 );-- Content & Activity
 CREATE TABLE contents (
  content_id INT PRIMARY KEY AUTO_INCREMENT,
  course_id INT NOT NULL,
  type ENUM('lesson','video','pdf','forum','quiz'),
  title VARCHAR(150),
  body TEXT,
  file_url VARCHAR(255),
  position INT,
  FOREIGN KEY (course_id) REFERENCES courses(course_id)
 );
 CREATE TABLE activity_logs (
  log_id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  content_id INT NOT NULL,
  action VARCHAR(50),
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id),
  FOREIGN KEY (content_id) REFERENCES contents(content_id)
 );
 CREATE TABLE events (
  event_id INT PRIMARY KEY AUTO_INCREMENT,
  course_id INT NOT NULL,
  name VARCHAR(100),
  description TEXT,
  start_time DATETIME,
  end_time DATETIME,
  FOREIGN KEY (course_id) REFERENCES courses(course_id)
 );
 CREATE TABLE forum_posts (
  post_id INT PRIMARY KEY AUTO_INCREMENT,
  content_id INT,
  user_id INT NOT NULL,
  parent_post_id INT NULL,
  body TEXT,
  posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (content_id) REFERENCES contents(content_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id)
 );-- Mastery Assignments
 CREATE TABLE assignments (
  assignment_id INT PRIMARY KEY AUTO_INCREMENT,
  lesson_id INT NOT NULL,
  title VARCHAR(100),
  description TEXT,
  due_date DATE,
  total_marks INT DEFAULT 10,
  passing_score INT DEFAULT 7,
  FOREIGN KEY (lesson_id) REFERENCES contents(content_id)
 );
 CREATE TABLE assignment_questions (
  question_id INT PRIMARY KEY AUTO_INCREMENT,
  assignment_id INT NOT NULL,
  question_text TEXT,
  option_a VARCHAR(255),
  option_b VARCHAR(255),
  option_c VARCHAR(255),
  option_d VARCHAR(255),
  correct_option ENUM('A','B','C','D'),
  FOREIGN KEY (assignment_id) REFERENCES assignments(assignment_id)
 );
 CREATE TABLE student_assignment_attempts (
  attempt_id INT PRIMARY KEY AUTO_INCREMENT,
  student_id INT NOT NULL,
  assignment_id INT NOT NULL,
  score INT NOT NULL,
  passed BOOLEAN NOT NULL,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(student_id),
  FOREIGN KEY (assignment_id) REFERENCES assignments(assignment_id)
 );
 CREATE TABLE assignment_attempt_questions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  attempt_id INT NOT NULL,
  question_id INT NOT NULL,
  selected_option ENUM('A','B','C','D') NOT NULL,
  is_correct BOOLEAN NOT NULL,
  FOREIGN KEY (attempt_id) REFERENCES student_assignment_attempts(attempt_id),
  FOREIGN KEY (question_id) REFERENCES assignment_questions(question_id)
 );-- Progress summary (optional cache)
 CREATE TABLE student_progress (
  progress_id INT PRIMARY KEY AUTO_INCREMENT,
  student_id INT NOT NULL,
  course_id INT NOT NULL,
  chapters_completed INT DEFAULT 0,
  last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(student_id),
  FOREIGN KEY (course_id) REFERENCES courses(course_id)
 );

CREATE TABLE admins (
  admin_id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT UNIQUE NOT NULL,
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  email VARCHAR(100),
  contact_number VARCHAR(15),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);

CREATE TABLE student_payments (
  payment_id INT PRIMARY KEY AUTO_INCREMENT,
  student_id INT NOT NULL,
  amount DECIMAL(10, 2) NOT NULL,
  payment_method ENUM('card', 'bank_transfer', 'cash', 'other') NOT NULL,
  payment_status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
  reference_code VARCHAR(100),
  paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(student_id)
);

CREATE TABLE IF NOT EXISTS teacher_payments (
  teacher_payment_id INT PRIMARY KEY AUTO_INCREMENT,
  teacher_id INT NOT NULL,
  course_id INT NULL,
  lesson_count INT DEFAULT 0,
  rate_per_lesson DECIMAL(10,2) DEFAULT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_status ENUM('pending','completed','failed') DEFAULT 'pending',
  paid_at DATETIME NULL,
  created_by INT NULL,
  verified_by INT NULL,
  verified_at DATETIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id),
  FOREIGN KEY (course_id) REFERENCES courses(course_id),
  FOREIGN KEY (created_by) REFERENCES accountants(accountant_id),
  FOREIGN KEY (verified_by) REFERENCES accountants(accountant_id)
);


CREATE TABLE password_resets (
  reset_id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  otp_code VARCHAR(10) NOT NULL,
  expires_at DATETIME NOT NULL,
  used BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);


ALTER TABLE courses ADD COLUMN price DECIMAL(10,2) DEFAULT 0;


CREATE TABLE messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  receiver_id INT NOT NULL,
  message TEXT NOT NULL,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  is_read BOOLEAN DEFAULT 0,
  FOREIGN KEY (sender_id) REFERENCES users(user_id),
  FOREIGN KEY (receiver_id) REFERENCES users(user_id)
);

CREATE TABLE announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    audience ENUM('students', 'teachers', 'all') DEFAULT 'all',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE student_payments ADD COLUMN slip_url VARCHAR(255) NULL AFTER reference_code;
ALTER TABLE teachers ADD COLUMN contact_number VARCHAR(20) DEFAULT NULL AFTER email;

CREATE TABLE ceo (
  ceo_id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT UNIQUE NOT NULL,
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  email VARCHAR(100),
  contact_number VARCHAR(15),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);

CREATE TABLE cto (
  cto_id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT UNIQUE NOT NULL,
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  email VARCHAR(100),
  contact_number VARCHAR(15),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);

ALTER TABLE users 
ADD COLUMN profile_pic VARCHAR(255) DEFAULT 'uploads/default.png';


ALTER TABLE student_payments
  ADD COLUMN verified_by INT NULL AFTER payment_status,
  ADD COLUMN verified_at DATETIME NULL AFTER verified_by,
  ADD CONSTRAINT fk_student_payments_verified_by
    FOREIGN KEY (verified_by) REFERENCES accountants(accountant_id);

ALTER TABLE teacher_payments
  ADD COLUMN verified_by INT NULL AFTER payment_status,
  ADD COLUMN verified_at DATETIME NULL AFTER verified_by,
  ADD CONSTRAINT fk_teacher_payments_verified_by
    FOREIGN KEY (verified_by) REFERENCES accountants(accountant_id);

CREATE INDEX idx_student_payments_status_date ON student_payments (payment_status, paid_at);
CREATE INDEX idx_teacher_payments_status_date ON teacher_payments (payment_status, paid_at);

ALTER TABLE teacher_payments
  ADD COLUMN course_id INT NULL AFTER teacher_id,
  ADD COLUMN lesson_count INT DEFAULT NULL AFTER amount,
  ADD COLUMN rate_per_lesson DECIMAL(10,2) DEFAULT NULL AFTER lesson_count,
  ADD CONSTRAINT fk_tp_course FOREIGN KEY (course_id) REFERENCES courses(course_id);

-- If you later want to auto-generate counts from the DB:
ALTER TABLE contents
  ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN teacher_id INT NULL,
  ADD CONSTRAINT fk_contents_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id);

CREATE INDEX idx_contents_course_type_created ON contents (course_id, type, created_at);



-- Helpful index to count lessons quickly (if not present)
CREATE INDEX IF NOT EXISTS idx_contents_lesson_range ON contents (type, created_at, teacher_id, course_id);

ALTER TABLE teacher_payments
ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

CREATE TABLE teacher_rates (
  teacher_rate_id INT AUTO_INCREMENT PRIMARY KEY,
  board VARCHAR(100) NOT NULL,
  level VARCHAR(100) NOT NULL,
  rate_per_lesson DECIMAL(10,2) NOT NULL
);

ALTER TABLE teacher_payments
ADD COLUMN notes TEXT NULL;

ALTER TABLE teachers
ADD COLUMN balance DECIMAL(10,2) DEFAULT 0;

ALTER TABLE teacher_payments
ADD COLUMN payment_method ENUM('Cash','Bank Transfer','Online') NOT NULL DEFAULT 'Cash';

ALTER TABLE teacher_payments
ADD COLUMN course_id INT NOT NULL AFTER teacher_id;

ALTER TABLE teacher_payments
ADD CONSTRAINT fk_course
FOREIGN KEY (course_id) REFERENCES courses(course_id);

ALTER TABLE teacher_payments
  ADD COLUMN reference_code VARCHAR(100) NULL AFTER payment_method;
  
CREATE TABLE past_papers (
  paper_id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NULL,
  board ENUM('Cambridge','Edexcel','Local','Other') NOT NULL,
  level ENUM('O/L','A/L','IGCSE','Others') NOT NULL,
  subject VARCHAR(120) NULL,
  syllabus_code VARCHAR(20) NULL,        -- e.g., 0620, 9709
  year SMALLINT NOT NULL,
  session ENUM('Feb/Mar','May/Jun','Oct/Nov','Specimen','Other') DEFAULT 'May/Jun',
  paper_code VARCHAR(20) NULL,           -- e.g., P11, P12, Paper 1
  variant VARCHAR(10) NULL,              -- e.g., 11, 12
  qp_url VARCHAR(255) NOT NULL,          -- question paper PDF
  ms_url VARCHAR(255) NULL,              -- mark scheme PDF
  solution_url VARCHAR(255) NULL,        -- optional video solution
  tags VARCHAR(255) NULL,
  visibility ENUM('public','private') DEFAULT 'public',
  download_count INT NOT NULL DEFAULT 0,
  uploaded_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (course_id) REFERENCES courses(course_id),
  FOREIGN KEY (uploaded_by) REFERENCES users(user_id)
);

CREATE INDEX idx_papers_filters ON past_papers (board, level, year, course_id);
CREATE INDEX idx_papers_subject ON past_papers (SUBJECT);

CREATE TABLE user_remember_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  selector CHAR(18) NOT NULL UNIQUE,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE course_coordinators (
  coordinator_id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT UNIQUE NOT NULL,
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  email VARCHAR(100),
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);

ALTER TABLE contents
  ADD COLUMN description TEXT NULL AFTER title;
  
ALTER TABLE contents
  ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER resource_url;