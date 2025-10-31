<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dbPath = __DIR__ . '/data.sqlite';
    $needMigrate = !file_exists($dbPath);
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    if ($needMigrate) {
        migrate($pdo);
    } else {
        // Check and add start_offset_days if needed
        try {
            $pdo->query('SELECT start_offset_days FROM main_tasks LIMIT 1');
        } catch (PDOException $e) {
            $pdo->exec('ALTER TABLE main_tasks ADD COLUMN start_offset_days INTEGER NOT NULL DEFAULT 0;');
        }
    }
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL DEFAULT "untitled",
            start_date TEXT NOT NULL,
            created_at TEXT NOT NULL
        );'
    );

    $pdo->exec(
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            color TEXT NOT NULL,
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        );'
    );

    $pdo->exec(
        'CREATE TABLE main_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            position INTEGER NOT NULL,
            start_offset_days INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        );'
    );

    $pdo->exec(
        'CREATE TABLE subtasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            main_task_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            user_id INTEGER NULL,
            duration_days INTEGER NOT NULL,
            position INTEGER NOT NULL,
            FOREIGN KEY(main_task_id) REFERENCES main_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
        );'
    );
}

function nowIso(): string { return gmdate('c'); }

function todayIso(): string { return gmdate('Y-m-d'); }

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function jsonResponse($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function getMaxPosition(PDO $pdo, string $table, string $whereCol, int $whereId): int {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) AS max_pos FROM $table WHERE $whereCol = :id");
    $stmt->execute([':id' => $whereId]);
    $row = $stmt->fetch();
    return (int)$row['max_pos'];
}


