<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

// Very simple action-based API: api.php?r=action

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$pdo = db();
$r = $_GET['r'] ?? '';
$input = getJsonInput();

try {
    switch ($r) {
        case 'list_projects':
            // Admin only - check session
            session_start();
            if (!isset($_SESSION['admin_authenticated']) || !$_SESSION['admin_authenticated']) {
                jsonResponse(['error' => 'Unauthorized'], 401);
            }
            $rows = $pdo->query('SELECT id, name, slug, password, start_date, created_at FROM projects ORDER BY id DESC')->fetchAll();
            jsonResponse(['projects' => $rows]);
            break;

        case 'create_project':
            // Admin only - check session
            session_start();
            if (!isset($_SESSION['admin_authenticated']) || !$_SESSION['admin_authenticated']) {
                jsonResponse(['error' => 'Unauthorized'], 401);
            }
            $name = trim($input['name'] ?? 'untitled');
            $password = trim($input['password'] ?? '');
            $start = $input['start_date'] ?? todayIso();
            $slug = generateSlug($name, $pdo);
            $stmt = $pdo->prepare('INSERT INTO projects (name, slug, password, start_date, created_at) VALUES (:n, :slug, :pwd, :s, :c)');
            $stmt->execute([':n' => $name, ':slug' => $slug, ':pwd' => $password, ':s' => $start, ':c' => nowIso()]);
            $id = (int)$pdo->lastInsertId();
            jsonResponse(['id' => $id, 'slug' => $slug]);
            break;

        case 'get_project':
            $id = (int)($_GET['id'] ?? 0);
            $slug = $_GET['slug'] ?? null;
            
            if ($slug) {
                $stmt = $pdo->prepare('SELECT id, name, slug, password, start_date FROM projects WHERE slug = :slug');
                $stmt->execute([':slug' => $slug]);
                $p = $stmt->fetch();
            } else {
                $stmt = $pdo->prepare('SELECT id, name, slug, password, start_date FROM projects WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $p = $stmt->fetch();
            }
            
            if (!$p) jsonResponse(['error' => 'Not found'], 404);
            
            // Check password if provided
            $providedPassword = $input['password'] ?? '';
            if (!empty($p['password']) && $providedPassword !== $p['password']) {
                jsonResponse(['error' => 'Invalid password'], 403);
            }

            $projectId = (int)$p['id'];
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

            jsonResponse(['project' => $p, 'users' => $users, 'main_tasks' => $tasks]);
            break;

        case 'update_project':
            $id = (int)($input['id'] ?? 0);
            $name = isset($input['name']) ? trim((string)$input['name']) : null;
            $password = isset($input['password']) ? trim((string)$input['password']) : null;
            $start = $input['start_date'] ?? null;
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            
            // Check if user is authenticated (either admin or project password)
            session_start();
            $isAdmin = isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'];
            
            // For password updates, admin only
            if ($password !== null && !$isAdmin) {
                jsonResponse(['error' => 'Unauthorized'], 401);
            }
            
            if ($name === null && $start === null && $password === null) jsonResponse(['ok' => true]);
            
            if ($name !== null) {
                // Update name and regenerate slug if needed
                $stmt = $pdo->prepare('SELECT name FROM projects WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $old = $stmt->fetch();
                if ($old && $old['name'] !== $name) {
                    $newSlug = generateSlug($name, $pdo);
                    $stmt = $pdo->prepare('UPDATE projects SET name = :n, slug = :slug WHERE id = :id');
                    $stmt->execute([':n' => $name, ':slug' => $newSlug, ':id' => $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE projects SET name = :n WHERE id = :id');
                    $stmt->execute([':n' => $name, ':id' => $id]);
                }
            }
            if ($password !== null) {
                $stmt = $pdo->prepare('UPDATE projects SET password = :pwd WHERE id = :id');
                $stmt->execute([':pwd' => $password, ':id' => $id]);
            }
            if ($start !== null) {
                $stmt = $pdo->prepare('UPDATE projects SET start_date = :s WHERE id = :id');
                $stmt->execute([':s' => $start, ':id' => $id]);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'admin_login':
            session_start();
            $config = require __DIR__ . '/config.php';
            $password = trim($input['password'] ?? '');
            if ($password === $config['admin_password']) {
                $_SESSION['admin_authenticated'] = true;
                jsonResponse(['ok' => true]);
            } else {
                jsonResponse(['error' => 'Invalid password'], 401);
            }
            break;

        case 'admin_logout':
            session_start();
            unset($_SESSION['admin_authenticated']);
            jsonResponse(['ok' => true]);
            break;

        case 'check_project_password':
            $slug = trim($input['slug'] ?? '');
            $password = trim($input['password'] ?? '');
            if (empty($slug)) jsonResponse(['error' => 'slug required'], 400);
            $stmt = $pdo->prepare('SELECT id, password FROM projects WHERE slug = :slug');
            $stmt->execute([':slug' => $slug]);
            $project = $stmt->fetch();
            if (!$project) jsonResponse(['error' => 'Project not found'], 404);
            if (empty($project['password']) || $password === $project['password']) {
                jsonResponse(['ok' => true]);
            } else {
                jsonResponse(['error' => 'Invalid password'], 403);
            }
            break;

        case 'add_user':
            $pid = (int)($input['project_id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $color = $input['color'] ?? '#999999';
            if (!$pid || $name === '') jsonResponse(['error' => 'project_id and name required'], 400);
            $stmt = $pdo->prepare('INSERT INTO users (project_id, name, color) VALUES (:p, :n, :c)');
            $stmt->execute([':p' => $pid, ':n' => $name, ':c' => $color]);
            jsonResponse(['id' => (int)$pdo->lastInsertId()]);
            break;

        case 'update_user':
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            if (isset($input['name'])) {
                $stmt = $pdo->prepare('UPDATE users SET name = :n WHERE id = :id');
                $stmt->execute([':n' => trim((string)$input['name']), ':id' => $id]);
            }
            if (isset($input['color'])) {
                $stmt = $pdo->prepare('UPDATE users SET color = :c WHERE id = :id');
                $stmt->execute([':c' => (string)$input['color'], ':id' => $id]);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'delete_project':
            // Admin only - check session
            session_start();
            if (!isset($_SESSION['admin_authenticated']) || !$_SESSION['admin_authenticated']) {
                jsonResponse(['error' => 'Unauthorized'], 401);
            }
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            $pdo->prepare('DELETE FROM projects WHERE id = :id')->execute([':id' => $id]);
            jsonResponse(['ok' => true]);
            break;

        case 'delete_user':
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE subtasks SET user_id = NULL WHERE user_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
            $pdo->commit();
            jsonResponse(['ok' => true]);
            break;

        case 'add_main_task':
            $pid = (int)($input['project_id'] ?? 0);
            $name = trim($input['name'] ?? 'Main task');
            if (!$pid) jsonResponse(['error' => 'project_id required'], 400);
            $pos = getMaxPosition($pdo, 'main_tasks', 'project_id', $pid) + 1;
            $stmt = $pdo->prepare('INSERT INTO main_tasks (project_id, name, position) VALUES (:p, :n, :pos)');
            $stmt->execute([':p' => $pid, ':n' => $name, ':pos' => $pos]);
            $id = (int)$pdo->lastInsertId();
            jsonResponse(['id' => $id]);
            break;

        case 'update_main_task':
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            if (isset($input['name'])) {
                $stmt = $pdo->prepare('UPDATE main_tasks SET name = :n WHERE id = :id');
                $stmt->execute([':n' => trim((string)$input['name']), ':id' => $id]);
            }
            if (isset($input['start_offset_days'])) {
                $stmt = $pdo->prepare('UPDATE main_tasks SET start_offset_days = :o WHERE id = :id');
                $stmt->execute([':o' => max(0, (int)$input['start_offset_days']), ':id' => $id]);
            }
            if (isset($input['position'])) {
                $stmt = $pdo->prepare('UPDATE main_tasks SET position = :p WHERE id = :id');
                $stmt->execute([':p' => max(0, (int)$input['position']), ':id' => $id]);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'delete_main_task':
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            $pdo->prepare('DELETE FROM main_tasks WHERE id = :id')->execute([':id' => $id]);
            jsonResponse(['ok' => true]);
            break;

        case 'add_subtask':
            $mtid = (int)($input['main_task_id'] ?? 0);
            if (!$mtid) jsonResponse(['error' => 'main_task_id required'], 400);
            $name = trim($input['name'] ?? '');
            $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
            $duration = max(1, (int)($input['duration_days'] ?? 7));
            $pos = getMaxPosition($pdo, 'subtasks', 'main_task_id', $mtid) + 1;
            $stmt = $pdo->prepare('INSERT INTO subtasks (main_task_id, name, user_id, duration_days, position) VALUES (:m, :n, :u, :d, :p)');
            $stmt->execute([':m' => $mtid, ':n' => $name, ':u' => $userId, ':d' => $duration, ':p' => $pos]);
            jsonResponse(['id' => (int)$pdo->lastInsertId()]);
            break;

        case 'update_subtask':
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            if (isset($input['name'])) {
                $pdo->prepare('UPDATE subtasks SET name = :n WHERE id = :id')->execute([':n' => trim((string)$input['name']), ':id' => $id]);
            }
            if (array_key_exists('user_id', $input)) {
                $uid = $input['user_id'] === null ? null : (int)$input['user_id'];
                $stmt = $pdo->prepare('UPDATE subtasks SET user_id = :u WHERE id = :id');
                $stmt->bindValue(':u', $uid, $uid === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
            }
            if (isset($input['duration_days'])) {
                $d = max(1, (int)$input['duration_days']);
                $pdo->prepare('UPDATE subtasks SET duration_days = :d WHERE id = :id')->execute([':d' => $d, ':id' => $id]);
            }
            if (isset($input['position'])) {
                $p = max(0, (int)$input['position']);
                $pdo->prepare('UPDATE subtasks SET position = :p WHERE id = :id')->execute([':p' => $p, ':id' => $id]);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'delete_subtask':
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            // Re-pack positions after delete
            $pdo->beginTransaction();
            $row = $pdo->prepare('SELECT main_task_id, position FROM subtasks WHERE id = :id');
            $row->execute([':id' => $id]);
            $row = $row->fetch();
            if ($row) {
                $mtid = (int)$row['main_task_id'];
                $pos = (int)$row['position'];
                $pdo->prepare('DELETE FROM subtasks WHERE id = :id')->execute([':id' => $id]);
                $pdo->prepare('UPDATE subtasks SET position = position - 1 WHERE main_task_id = :m AND position > :p')->execute([':m' => $mtid, ':p' => $pos]);
            }
            $pdo->commit();
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}


