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
            $rows = $pdo->query('SELECT id, name, start_date, created_at FROM projects ORDER BY id DESC')->fetchAll();
            jsonResponse(['projects' => $rows]);
            break;

        case 'create_project':
            $name = trim($input['name'] ?? 'untitled');
            $start = $input['start_date'] ?? todayIso();
            $stmt = $pdo->prepare('INSERT INTO projects (name, start_date, created_at) VALUES (:n, :s, :c)');
            $stmt->execute([':n' => $name, ':s' => $start, ':c' => nowIso()]);
            $id = (int)$pdo->lastInsertId();
            jsonResponse(['id' => $id]);
            break;

        case 'get_project':
            $id = (int)($_GET['id'] ?? 0);
            $project = $pdo->prepare('SELECT id, name, start_date FROM projects WHERE id = :id');
            $project->execute([':id' => $id]);
            $p = $project->fetch();
            if (!$p) jsonResponse(['error' => 'Not found'], 404);

            $users = $pdo->prepare('SELECT id, name, color FROM users WHERE project_id = :pid ORDER BY id ASC');
            $users->execute([':pid' => $id]);
            $users = $users->fetchAll();

            $tasksStmt = $pdo->prepare('SELECT id, name, position FROM main_tasks WHERE project_id = :pid ORDER BY position ASC');
            $tasksStmt->execute([':pid' => $id]);
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
            $start = $input['start_date'] ?? null;
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            if ($name === null && $start === null) jsonResponse(['ok' => true]);
            if ($name !== null) {
                $stmt = $pdo->prepare('UPDATE projects SET name = :n WHERE id = :id');
                $stmt->execute([':n' => $name, ':id' => $id]);
            }
            if ($start !== null) {
                $stmt = $pdo->prepare('UPDATE projects SET start_date = :s WHERE id = :id');
                $stmt->execute([':s' => $start, ':id' => $id]);
            }
            jsonResponse(['ok' => true]);
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
            $name = trim($input['name'] ?? '');
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            $stmt = $pdo->prepare('UPDATE main_tasks SET name = :n WHERE id = :id');
            $stmt->execute([':n' => $name, ':id' => $id]);
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


