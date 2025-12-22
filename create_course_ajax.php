<?php
declare(strict_types=1);
session_start();
require 'db_connect.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'teacher') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Invalid method.']);
    exit;
}

// CSRF
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'message' => 'Invalid CSRF token.',
        'errors'  => ['Invalid CSRF token.']
    ]);
    exit;
}

// Fetch teacher_id
$user_id = (int)($_SESSION['user_id'] ?? 0);
$teacher_id = null;
if ($st = $conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?")) {
    $st->bind_param("i", $user_id);
    $st->execute();
    $st->bind_result($teacher_id);
    $st->fetch();
    $st->close();
}
if (!$teacher_id) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Access denied (no teacher record).']);
    exit;
}

// Allowed presets
$allowedBoards = ['Cambridge', 'Edexcel', 'Local', 'Other'];
$allowedLevels = ['O/L', 'A/L', 'IGCSE', 'Other'];

$errors = [];
$posted_name        = trim($_POST['name'] ?? '');
$posted_description = trim($_POST['description'] ?? '');
$posted_board       = $_POST['board'] ?? '';
$posted_board_other = trim($_POST['board_other'] ?? '');
$posted_level       = $_POST['level'] ?? '';
$posted_level_other = trim($_POST['level_other'] ?? '');

// Validation (same rules as create_course.php)
if ($posted_name === '') {
    $errors[] = "Course name is required.";
} elseif (mb_strlen($posted_name) > 120) {
    $errors[] = "Course name is too long (max 120 characters).";
}
if ($posted_description === '') {
    $errors[] = "Description is required.";
} elseif (mb_strlen($posted_description) > 2000) {
    $errors[] = "Description is too long (max 2000 characters).";
}

// Validate board
if (!in_array($posted_board, $allowedBoards, true)) {
    $errors[] = "Invalid board selection.";
}
$boardValue = $posted_board === 'Other' ? $posted_board_other : $posted_board;
$boardValue = trim($boardValue);
if ($boardValue === '') {
    $errors[] = "Please specify a board.";
} elseif (mb_strlen($boardValue) > 60) {
    $errors[] = "Board is too long (max 60 characters).";
}

// Validate level
if (!in_array($posted_level, $allowedLevels, true)) {
    $errors[] = "Invalid level selection.";
}
$levelValue = $posted_level === 'Other' ? $posted_level_other : $posted_level;
$levelValue = trim($levelValue);
if ($levelValue === '') {
    $errors[] = "Please specify a level.";
} elseif (mb_strlen($levelValue) > 60) {
    $errors[] = "Level is too long (max 60 characters).";
}

if (!empty($errors)) {
    echo json_encode([
        'ok'      => false,
        'message' => 'Validation failed.',
        'errors'  => $errors
    ]);
    exit;
}

// DB operations
try {
    $conn->begin_transaction();

    // Reuse existing course_types if available
    $course_type_id = null;
    if ($st = $conn->prepare("SELECT course_type_id FROM course_types WHERE board = ? AND level = ? LIMIT 1")) {
        $st->bind_param("ss", $boardValue, $levelValue);
        $st->execute();
        $st->bind_result($course_type_id);
        $st->fetch();
        $st->close();
    }

    if (!$course_type_id) {
        if ($st = $conn->prepare("INSERT INTO course_types (board, level) VALUES (?, ?)")) {
            $st->bind_param("ss", $boardValue, $levelValue);
            if (!$st->execute()) {
                throw new Exception("Failed to insert course type.");
            }
            $course_type_id = $conn->insert_id;
            $st->close();
        } else {
            throw new Exception("Failed to prepare course type insert.");
        }
    }

    // Insert course
    $course_id = null;
    if ($st = $conn->prepare("INSERT INTO courses (name, description, course_type_id) VALUES (?, ?, ?)")) {
        $st->bind_param("ssi", $posted_name, $posted_description, $course_type_id);
        if (!$st->execute()) {
            throw new Exception("Failed to insert course.");
        }
        $course_id = $conn->insert_id;
        $st->close();
    } else {
        throw new Exception("Failed to prepare course insert.");
    }

    // Link teacher to course (avoid duplicates)
    $exists = 0;
    if ($st = $conn->prepare("SELECT 1 FROM teacher_courses WHERE teacher_id = ? AND course_id = ? LIMIT 1")) {
        $st->bind_param("ii", $teacher_id, $course_id);
        $st->execute();
        $st->store_result();
        $exists = $st->num_rows > 0 ? 1 : 0;
        $st->close();
    }
    if (!$exists) {
        if ($st = $conn->prepare("INSERT INTO teacher_courses (teacher_id, course_id) VALUES (?, ?)")) {
            $st->bind_param("ii", $teacher_id, $course_id);
            if (!$st->execute()) {
                throw new Exception("Failed to link teacher to course.");
            }
            $st->close();
        } else {
            throw new Exception("Failed to prepare teacher_course insert.");
        }
    }

    $conn->commit();

    echo json_encode([
        'ok'      => true,
        'message' => "Course created.",
        'course'  => [
            'id'    => (int)$course_id,
            'name'  => $posted_name,
            'board' => $boardValue,
            'level' => $levelValue,
        ]
    ]);
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'message' => 'Database error.',
        'errors'  => ['Database error: ' . $e->getMessage()]
    ]);
    exit;
}