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
            
            // Get current state to detect changes
            $stmt = $pdo->prepare('SELECT name, start_date, password FROM projects WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $current = $stmt->fetch();
            if (!$current) jsonResponse(['error' => 'Project not found'], 404);
            
            $changes = [];
            $hasChanges = false;
            
            if ($name !== null && $name !== $current['name']) {
                // Update name and regenerate slug if needed
                $newSlug = generateSlug($name, $pdo);
                $stmt = $pdo->prepare('UPDATE projects SET name = :n, slug = :slug WHERE id = :id');
                $stmt->execute([':n' => $name, ':slug' => $newSlug, ':id' => $id]);
                $changes['name'] = ['old' => $current['name'], 'new' => $name];
                $hasChanges = true;
            } elseif ($name !== null) {
                $stmt = $pdo->prepare('UPDATE projects SET name = :n WHERE id = :id');
                $stmt->execute([':n' => $name, ':id' => $id]);
            }
            if ($password !== null && $password !== $current['password']) {
                $stmt = $pdo->prepare('UPDATE projects SET password = :pwd WHERE id = :id');
                $stmt->execute([':pwd' => $password, ':id' => $id]);
                $changes['password'] = ['changed' => true];
                $hasChanges = true;
            }
            if ($start !== null && $start !== $current['start_date']) {
                $stmt = $pdo->prepare('UPDATE projects SET start_date = :s WHERE id = :id');
                $stmt->execute([':s' => $start, ':id' => $id]);
                $changes['start_date'] = ['old' => $current['start_date'], 'new' => $start];
                $hasChanges = true;
            }
            
            if ($hasChanges) {
                trackChange($pdo, $id, 'update_project', 'project', $id, $changes, 'Project updated');
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
            $id = (int)$pdo->lastInsertId();
            trackChange($pdo, $pid, 'add_user', 'user', $id, ['name' => $name, 'color' => $color], 'User added: ' . $name);
            jsonResponse(['id' => $id]);
            break;

        case 'update_user':
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            
            // Get current state
            $stmt = $pdo->prepare('SELECT project_id, name, color FROM users WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $current = $stmt->fetch();
            if (!$current) jsonResponse(['error' => 'User not found'], 404);
            
            $changes = [];
            $hasChanges = false;
            
            if (isset($input['name'])) {
                $newName = trim((string)$input['name']);
                if ($newName !== $current['name']) {
                    $stmt = $pdo->prepare('UPDATE users SET name = :n WHERE id = :id');
                    $stmt->execute([':n' => $newName, ':id' => $id]);
                    $changes['name'] = ['old' => $current['name'], 'new' => $newName];
                    $hasChanges = true;
                }
            }
            if (isset($input['color'])) {
                $newColor = (string)$input['color'];
                if ($newColor !== $current['color']) {
                    $stmt = $pdo->prepare('UPDATE users SET color = :c WHERE id = :id');
                    $stmt->execute([':c' => $newColor, ':id' => $id]);
                    $changes['color'] = ['old' => $current['color'], 'new' => $newColor];
                    $hasChanges = true;
                }
            }
            
            if ($hasChanges) {
                trackChange($pdo, (int)$current['project_id'], 'update_user', 'user', $id, $changes, 'User updated');
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
            
            // Get user info before deletion
            $stmt = $pdo->prepare('SELECT project_id, name FROM users WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $user = $stmt->fetch();
            if (!$user) jsonResponse(['error' => 'User not found'], 404);
            
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE subtasks SET user_id = NULL WHERE user_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
            $pdo->commit();
            
            trackChange($pdo, (int)$user['project_id'], 'delete_user', 'user', $id, ['name' => $user['name']], 'User deleted: ' . $user['name']);
            
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
            trackChange($pdo, $pid, 'add_main_task', 'main_task', $id, ['name' => $name], 'Task added: ' . $name);
            jsonResponse(['id' => $id]);
            break;

        case 'update_main_task':
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            
            // Get current state and project_id
            $stmt = $pdo->prepare('SELECT project_id, name, start_offset_days, position FROM main_tasks WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $current = $stmt->fetch();
            if (!$current) jsonResponse(['error' => 'Task not found'], 404);
            
            $changes = [];
            $hasChanges = false;
            
            if (isset($input['name'])) {
                $newName = trim((string)$input['name']);
                if ($newName !== $current['name']) {
                    $stmt = $pdo->prepare('UPDATE main_tasks SET name = :n WHERE id = :id');
                    $stmt->execute([':n' => $newName, ':id' => $id]);
                    $changes['name'] = ['old' => $current['name'], 'new' => $newName];
                    $hasChanges = true;
                }
            }
            if (isset($input['start_offset_days'])) {
                $newOffset = max(0, (int)$input['start_offset_days']);
                if ($newOffset !== (int)$current['start_offset_days']) {
                    $stmt = $pdo->prepare('UPDATE main_tasks SET start_offset_days = :o WHERE id = :id');
                    $stmt->execute([':o' => $newOffset, ':id' => $id]);
                    $changes['start_offset_days'] = ['old' => (int)$current['start_offset_days'], 'new' => $newOffset];
                    $hasChanges = true;
                }
            }
            if (isset($input['position'])) {
                $newPos = max(0, (int)$input['position']);
                if ($newPos !== (int)$current['position']) {
                    $stmt = $pdo->prepare('UPDATE main_tasks SET position = :p WHERE id = :id');
                    $stmt->execute([':p' => $newPos, ':id' => $id]);
                    $changes['position'] = ['old' => (int)$current['position'], 'new' => $newPos];
                    $hasChanges = true;
                }
            }
            
            if ($hasChanges) {
                trackChange($pdo, (int)$current['project_id'], 'update_main_task', 'main_task', $id, $changes, 'Task updated');
            }
            
            jsonResponse(['ok' => true]);
            break;

        case 'delete_main_task':
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            
            // Get task info before deletion
            $stmt = $pdo->prepare('SELECT project_id, name FROM main_tasks WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $task = $stmt->fetch();
            if (!$task) jsonResponse(['error' => 'Task not found'], 404);
            
            $pdo->prepare('DELETE FROM main_tasks WHERE id = :id')->execute([':id' => $id]);
            
            trackChange($pdo, (int)$task['project_id'], 'delete_main_task', 'main_task', $id, ['name' => $task['name']], 'Task deleted: ' . $task['name']);
            
            jsonResponse(['ok' => true]);
            break;

        case 'add_subtask':
            $mtid = (int)($input['main_task_id'] ?? 0);
            if (!$mtid) jsonResponse(['error' => 'main_task_id required'], 400);
            $name = trim($input['name'] ?? '');
            $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
            $duration = max(1, (int)($input['duration_days'] ?? 7));
            $pos = getMaxPosition($pdo, 'subtasks', 'main_task_id', $mtid) + 1;
            
            // Get project_id from main_task
            $stmt = $pdo->prepare('SELECT project_id FROM main_tasks WHERE id = :id');
            $stmt->execute([':id' => $mtid]);
            $task = $stmt->fetch();
            if (!$task) jsonResponse(['error' => 'Main task not found'], 404);
            $projectId = (int)$task['project_id'];
            
            $stmt = $pdo->prepare('INSERT INTO subtasks (main_task_id, name, user_id, duration_days, position) VALUES (:m, :n, :u, :d, :p)');
            $stmt->execute([':m' => $mtid, ':n' => $name, ':u' => $userId, ':d' => $duration, ':p' => $pos]);
            $id = (int)$pdo->lastInsertId();
            
            trackChange($pdo, $projectId, 'add_subtask', 'subtask', $id, ['name' => $name, 'duration_days' => $duration], 'Subtask added: ' . $name);
            
            jsonResponse(['id' => $id]);
            break;

        case 'update_subtask':
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            
            // Get current state and project_id
            $stmt = $pdo->prepare('SELECT s.name, s.user_id, s.duration_days, s.position, mt.project_id FROM subtasks s JOIN main_tasks mt ON s.main_task_id = mt.id WHERE s.id = :id');
            $stmt->execute([':id' => $id]);
            $current = $stmt->fetch();
            if (!$current) jsonResponse(['error' => 'Subtask not found'], 404);
            
            $changes = [];
            $hasChanges = false;
            
            if (isset($input['name'])) {
                $newName = trim((string)$input['name']);
                if ($newName !== $current['name']) {
                    $pdo->prepare('UPDATE subtasks SET name = :n WHERE id = :id')->execute([':n' => $newName, ':id' => $id]);
                    $changes['name'] = ['old' => $current['name'], 'new' => $newName];
                    $hasChanges = true;
                }
            }
            if (array_key_exists('user_id', $input)) {
                $uid = $input['user_id'] === null ? null : (int)$input['user_id'];
                $currentUid = $current['user_id'] === null ? null : (int)$current['user_id'];
                if ($uid !== $currentUid) {
                    $stmt = $pdo->prepare('UPDATE subtasks SET user_id = :u WHERE id = :id');
                    $stmt->bindValue(':u', $uid, $uid === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                    $stmt->execute();
                    $changes['user_id'] = ['old' => $currentUid, 'new' => $uid];
                    $hasChanges = true;
                }
            }
            if (isset($input['duration_days'])) {
                $d = max(1, (int)$input['duration_days']);
                if ($d !== (int)$current['duration_days']) {
                    $pdo->prepare('UPDATE subtasks SET duration_days = :d WHERE id = :id')->execute([':d' => $d, ':id' => $id]);
                    $changes['duration_days'] = ['old' => (int)$current['duration_days'], 'new' => $d];
                    $hasChanges = true;
                }
            }
            if (isset($input['position'])) {
                $p = max(0, (int)$input['position']);
                if ($p !== (int)$current['position']) {
                    $pdo->prepare('UPDATE subtasks SET position = :p WHERE id = :id')->execute([':p' => $p, ':id' => $id]);
                    $changes['position'] = ['old' => (int)$current['position'], 'new' => $p];
                    $hasChanges = true;
                }
            }
            
            if ($hasChanges) {
                trackChange($pdo, (int)$current['project_id'], 'update_subtask', 'subtask', $id, $changes, 'Subtask updated');
            }
            
            jsonResponse(['ok' => true]);
            break;

        case 'delete_subtask':
            $id = (int)($input['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            
            // Get subtask info and project_id before deletion
            $stmt = $pdo->prepare('SELECT s.name, s.main_task_id, s.position, mt.project_id FROM subtasks s JOIN main_tasks mt ON s.main_task_id = mt.id WHERE s.id = :id');
            $stmt->execute([':id' => $id]);
            $subtask = $stmt->fetch();
            if (!$subtask) jsonResponse(['error' => 'Subtask not found'], 404);
            
            // Re-pack positions after delete
            $pdo->beginTransaction();
            $mtid = (int)$subtask['main_task_id'];
            $pos = (int)$subtask['position'];
            $pdo->prepare('DELETE FROM subtasks WHERE id = :id')->execute([':id' => $id]);
            $pdo->prepare('UPDATE subtasks SET position = position - 1 WHERE main_task_id = :m AND position > :p')->execute([':m' => $mtid, ':p' => $pos]);
            $pdo->commit();
            
            trackChange($pdo, (int)$subtask['project_id'], 'delete_subtask', 'subtask', $id, ['name' => $subtask['name']], 'Subtask deleted: ' . $subtask['name']);
            
            jsonResponse(['ok' => true]);
            break;

        case 'get_project_history':
            $id = (int)($_GET['id'] ?? 0);
            $slug = $_GET['slug'] ?? null;
            
            if ($slug) {
                $stmt = $pdo->prepare('SELECT id FROM projects WHERE slug = :slug');
                $stmt->execute([':slug' => $slug]);
                $p = $stmt->fetch();
                if (!$p) jsonResponse(['error' => 'Project not found'], 404);
                $id = (int)$p['id'];
            }
            
            if (!$id) jsonResponse(['error' => 'id or slug required'], 400);
            
            // Get snapshots
            $stmt = $pdo->prepare('SELECT id, created_at, description FROM project_snapshots WHERE project_id = :pid ORDER BY created_at DESC LIMIT 100');
            $stmt->execute([':pid' => $id]);
            $snapshots = $stmt->fetchAll();
            
            // Get recent events
            $stmt = $pdo->prepare('SELECT id, snapshot_id, event_type, entity_type, entity_id, changes, created_at FROM project_events WHERE project_id = :pid ORDER BY created_at DESC LIMIT 100');
            $stmt->execute([':pid' => $id]);
            $events = $stmt->fetchAll();
            
            // Decode JSON fields
            foreach ($events as &$event) {
                $event['changes'] = json_decode($event['changes'], true);
            }
            
            jsonResponse(['snapshots' => $snapshots, 'events' => $events]);
            break;

        case 'restore_snapshot':
            $snapshotId = (int)($input['snapshot_id'] ?? 0);
            if (!$snapshotId) jsonResponse(['error' => 'snapshot_id required'], 400);
            
            // Get snapshot
            $stmt = $pdo->prepare('SELECT project_id, snapshot_data FROM project_snapshots WHERE id = :id');
            $stmt->execute([':id' => $snapshotId]);
            $snapshot = $stmt->fetch();
            if (!$snapshot) jsonResponse(['error' => 'Snapshot not found'], 404);
            
            $projectId = (int)$snapshot['project_id'];
            $data = json_decode($snapshot['snapshot_data'], true);
            if (!$data) jsonResponse(['error' => 'Invalid snapshot data'], 500);
            
            $pdo->beginTransaction();
            try {
                // Delete current data
                $pdo->prepare('DELETE FROM subtasks WHERE main_task_id IN (SELECT id FROM main_tasks WHERE project_id = :pid)')->execute([':pid' => $projectId]);
                $pdo->prepare('DELETE FROM main_tasks WHERE project_id = :pid')->execute([':pid' => $projectId]);
                $pdo->prepare('DELETE FROM users WHERE project_id = :pid')->execute([':pid' => $projectId]);
                
                // Restore project
                $p = $data['project'];
                $pdo->prepare('UPDATE projects SET name = :n, start_date = :s WHERE id = :id')->execute([
                    ':n' => $p['name'],
                    ':s' => $p['start_date'],
                    ':id' => $projectId
                ]);
                
                // Restore users
                $userMap = []; // Map old user ID to new user ID
                foreach ($data['users'] as $user) {
                    $oldUserId = $user['id'];
                    $stmt = $pdo->prepare('INSERT INTO users (project_id, name, color) VALUES (:pid, :n, :c)');
                    $stmt->execute([
                        ':pid' => $projectId,
                        ':n' => $user['name'],
                        ':c' => $user['color']
                    ]);
                    $newUserId = (int)$pdo->lastInsertId();
                    $userMap[$oldUserId] = $newUserId;
                }
                
                // Restore main tasks
                foreach ($data['main_tasks'] as $task) {
                    $stmt = $pdo->prepare('INSERT INTO main_tasks (project_id, name, position, start_offset_days) VALUES (:pid, :n, :p, :o)');
                    $stmt->execute([
                        ':pid' => $projectId,
                        ':n' => $task['name'],
                        ':p' => $task['position'],
                        ':o' => $task['start_offset_days']
                    ]);
                    $taskId = (int)$pdo->lastInsertId();
                    
                    // Restore subtasks
                    foreach ($task['subtasks'] as $subtask) {
                        $userId = null;
                        if ($subtask['user_id'] && isset($userMap[$subtask['user_id']])) {
                            $userId = $userMap[$subtask['user_id']];
                        }
                        
                        $pdo->prepare('INSERT INTO subtasks (main_task_id, name, user_id, duration_days, position) VALUES (:mtid, :n, :uid, :d, :p)')->execute([
                            ':mtid' => $taskId,
                            ':n' => $subtask['name'],
                            ':uid' => $userId,
                            ':d' => $subtask['duration_days'],
                            ':p' => $subtask['position']
                        ]);
                    }
                }
                
                $pdo->commit();
                
                // Create snapshot of restore
                trackChange($pdo, $projectId, 'restore_snapshot', 'project', $projectId, ['snapshot_id' => $snapshotId], 'Restored from snapshot');
                
                jsonResponse(['ok' => true]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                jsonResponse(['error' => $e->getMessage()], 500);
            }
            break;

        default:
            jsonResponse(['error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}


