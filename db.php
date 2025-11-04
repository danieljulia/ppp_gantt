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
        // Check and add slug and password if needed
        try {
            $pdo->query('SELECT slug FROM projects LIMIT 1');
        } catch (PDOException $e) {
            $pdo->exec('ALTER TABLE projects ADD COLUMN slug TEXT;');
            $pdo->exec('ALTER TABLE projects ADD COLUMN password TEXT NOT NULL DEFAULT "";');
            // Generate slugs for existing projects
            $projects = $pdo->query('SELECT id, name FROM projects')->fetchAll();
            foreach ($projects as $project) {
                $slug = generateSlug($project['name'], $pdo);
                $pdo->prepare('UPDATE projects SET slug = :slug WHERE id = :id')->execute([
                    ':slug' => $slug,
                    ':id' => $project['id']
                ]);
            }
            $pdo->exec('CREATE UNIQUE INDEX idx_projects_slug ON projects(slug);');
        }
        // Check and add history tables if needed
        try {
            $pdo->query('SELECT id FROM project_snapshots LIMIT 1');
        } catch (PDOException $e) {
            migrateHistoryTables($pdo);
        }
    }
    return $pdo;
}

function migrateHistoryTables(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE project_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            snapshot_data TEXT NOT NULL,
            created_at TEXT NOT NULL,
            description TEXT,
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
        );'
    );
    
    $pdo->exec(
        'CREATE TABLE project_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            snapshot_id INTEGER NULL,
            event_type TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER,
            changes TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY(snapshot_id) REFERENCES project_snapshots(id) ON DELETE SET NULL
        );'
    );
    
    $pdo->exec('CREATE INDEX idx_snapshots_project ON project_snapshots(project_id, created_at DESC);');
    $pdo->exec('CREATE INDEX idx_events_project ON project_events(project_id, created_at DESC);');
}

function migrate(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL DEFAULT "untitled",
            slug TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL DEFAULT "",
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
    
    migrateHistoryTables($pdo);
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

function generateSlug(string $name, PDO $pdo): string {
    // Convert to lowercase, replace spaces and special chars with hyphens
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if (empty($slug)) {
        $slug = 'project';
    }
    
    // Ensure uniqueness
    $baseSlug = $slug;
    $counter = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        if ($stmt->fetchColumn() == 0) {
            break;
        }
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
    
    return $slug;
}

// History/Versioning functions
function getProjectSnapshot(PDO $pdo, int $projectId): array {
    $stmt = $pdo->prepare('SELECT id, name, slug, password, start_date FROM projects WHERE id = :id');
    $stmt->execute([':id' => $projectId]);
    $project = $stmt->fetch();
    if (!$project) return [];
    
    $users = $pdo->prepare('SELECT id, name, color FROM users WHERE project_id = :pid ORDER BY id ASC');
    $users->execute([':pid' => $projectId]);
    $users = $users->fetchAll();
    
    $tasksStmt = $pdo->prepare('SELECT id, name, position, start_offset_days FROM main_tasks WHERE project_id = :pid ORDER BY position ASC');
    $tasksStmt->execute([':pid' => $projectId]);
    $tasks = $tasksStmt->fetchAll();
    
    $subStmt = $pdo->prepare('SELECT id, main_task_id, name, user_id, duration_days, position FROM subtasks WHERE main_task_id = :mtid ORDER BY position ASC');
    foreach ($tasks as &$t) {
        $subStmt->execute([':mtid' => $t['id']]);
        $t['subtasks'] = $subStmt->fetchAll();
    }
    
    return [
        'project' => $project,
        'users' => $users,
        'main_tasks' => $tasks
    ];
}

function createSnapshot(PDO $pdo, int $projectId, ?string $description = null): int {
    $snapshot = getProjectSnapshot($pdo, $projectId);
    if (empty($snapshot)) return 0;
    
    $snapshotData = json_encode($snapshot);
    $stmt = $pdo->prepare('INSERT INTO project_snapshots (project_id, snapshot_data, created_at, description) VALUES (:pid, :data, :time, :desc)');
    $stmt->execute([
        ':pid' => $projectId,
        ':data' => $snapshotData,
        ':time' => nowIso(),
        ':desc' => $description
    ]);
    
    return (int)$pdo->lastInsertId();
}

function logEvent(PDO $pdo, int $projectId, string $eventType, string $entityType, ?int $entityId, array $changes, ?int $snapshotId = null): void {
    $stmt = $pdo->prepare('INSERT INTO project_events (project_id, snapshot_id, event_type, entity_type, entity_id, changes, created_at) VALUES (:pid, :sid, :etype, :entype, :eid, :ch, :time)');
    $stmt->execute([
        ':pid' => $projectId,
        ':sid' => $snapshotId,
        ':etype' => $eventType,
        ':entype' => $entityType,
        ':eid' => $entityId,
        ':ch' => json_encode($changes),
        ':time' => nowIso()
    ]);
}

function shouldCreateSnapshot(PDO $pdo, int $projectId, int $eventsSinceLastSnapshot = 0): bool {
    // Create snapshot if:
    // 1. No snapshots exist yet
    // 2. More than 10 events since last snapshot
    // 3. More than 1 hour since last snapshot
    
    $stmt = $pdo->prepare('SELECT created_at FROM project_snapshots WHERE project_id = :pid ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([':pid' => $projectId]);
    $lastSnapshot = $stmt->fetch();
    
    if (!$lastSnapshot) {
        return true; // First snapshot
    }
    
    if ($eventsSinceLastSnapshot >= 10) {
        return true; // Too many events
    }
    
    $lastSnapshotTime = strtotime($lastSnapshot['created_at']);
    $oneHourAgo = time() - 3600;
    if ($lastSnapshotTime < $oneHourAgo) {
        return true; // Too old
    }
    
    return false;
}

function getEventsSinceLastSnapshot(PDO $pdo, int $projectId): int {
    $stmt = $pdo->prepare('SELECT MAX(id) as snapshot_id FROM project_snapshots WHERE project_id = :pid');
    $stmt->execute([':pid' => $projectId]);
    $result = $stmt->fetch();
    $snapshotId = $result['snapshot_id'] ?? null;
    
    if ($snapshotId === null) {
        // Count all events if no snapshots
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM project_events WHERE project_id = :pid');
        $stmt->execute([':pid' => $projectId]);
        return (int)$stmt->fetchColumn();
    }
    
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM project_events WHERE project_id = :pid AND (snapshot_id IS NULL OR snapshot_id > :sid)');
    $stmt->execute([':pid' => $projectId, ':sid' => $snapshotId]);
    return (int)$stmt->fetchColumn();
}

function trackChange(PDO $pdo, int $projectId, string $eventType, string $entityType, ?int $entityId, array $changes, ?string $description = null): void {
    $eventsSinceLastSnapshot = getEventsSinceLastSnapshot($pdo, $projectId);
    $shouldSnapshot = shouldCreateSnapshot($pdo, $projectId, $eventsSinceLastSnapshot);
    
    $snapshotId = null;
    if ($shouldSnapshot) {
        $snapshotId = createSnapshot($pdo, $projectId, $description);
    }
    
    logEvent($pdo, $projectId, $eventType, $entityType, $entityId, $changes, $snapshotId);
}


