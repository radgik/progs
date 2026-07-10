<?php
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/photo_lib.php';

// Авто-миграции (совместимо со старым MySQL)
photo_run_migrations($mysql);
$chk = $mysql->query("SHOW COLUMNS FROM tasks LIKE 'rejection_reason'");
if ($chk && $chk->num_rows === 0) {
    $mysql->query("ALTER TABLE tasks ADD COLUMN rejection_reason TEXT DEFAULT NULL");
}
$chk = $mysql->query("SHOW COLUMNS FROM task_executions LIKE 'rejection_reason'");
if ($chk && $chk->num_rows === 0) {
    $mysql->query("ALTER TABLE task_executions ADD COLUMN rejection_reason TEXT DEFAULT NULL");
}

function executionIsOpen($exec) {
    return photo_execution_is_open($exec);
}

function syncTaskPhotosCount($mysql, $taskId) {
    photo_sync_task_count($mysql, $taskId);
}

function syncExecutionPhotosCount($mysql, $execId) {
    photo_sync_exec_count($mysql, $execId);
}

function executionOpenCloseAt($dateStr, $openTime, $closeTime) {
    $openAt = $dateStr . ' ' . $openTime . ':00';
    if ($closeTime < $openTime) {
        $closeDay = new DateTime($dateStr);
        $closeDay->modify('+1 day');
        $closeAt = $closeDay->format('Y-m-d') . ' ' . $closeTime . ':00';
    } else {
        $closeAt = $dateStr . ' ' . $closeTime . ':00';
    }
    return array($openAt, $closeAt);
}


function api_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getAuthUser($mysql) {
    $token = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION']))         $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
    elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $token = str_replace('Bearer ', '', $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    elseif (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v)
            if (strtolower($k) === 'authorization') { $token = str_replace('Bearer ', '', $v); break; }
    }
    if (empty($token) && !empty($_POST['token'])) $token = $_POST['token'];
    if (empty($token) && !empty($_GET['token'])) $token = $_GET['token'];
    if (empty($token)) return null;
    $token = trim($token);
    $stmt = $mysql->prepare("SELECT u.id,u.login,u.name,u.role FROM users u INNER JOIN sessions s ON s.user_id=u.id WHERE s.token=? AND s.expires_at>NOW() LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $token);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

if (!isset($mysql)) api_response(array('error'=>'Ошибка подключения к базе данных'), 500);

list($action, $id, $subAction) = photo_parse_route($_SERVER['REQUEST_URI']);
$subId = null;

$method = $_SERVER['REQUEST_METHOD'];

// ── LOGIN ────────────────────────────────────────────────────
if ($action === 'login') {
    if ($method !== 'POST') api_response(array('error'=>'Метод не разрешен'), 405);
    $data = json_decode(file_get_contents('php://input'), true);
    $login = isset($data['login']) ? $data['login'] : '';
    $password = isset($data['password']) ? $data['password'] : '';
    if (empty($login)||empty($password)) api_response(array('error'=>'Логин и пароль обязательны'), 400);
    $stmt = $mysql->prepare("SELECT * FROM users WHERE login=?");
    if (!$stmt) api_response(array('error'=>'Prepare error: '.$mysql->error), 500);
    $stmt->bind_param("s", $login); $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) api_response(array('error'=>'Пользователь не найден'), 401);
    if (!password_verify($password, $user['password'])) api_response(array('error'=>'Неверный пароль'), 401);
    $token = md5(uniqid(mt_rand(),true)).md5(uniqid(mt_rand(),true));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    $stmt2 = $mysql->prepare("INSERT INTO sessions (user_id,token,expires_at) VALUES (?,?,?)");
    if (!$stmt2) api_response(array('error'=>'Sessions error: '.$mysql->error), 500);
    $stmt2->bind_param("iss", $user['id'], $token, $expiresAt); $stmt2->execute();
    api_response(array('token'=>$token,'user'=>array('id'=>$user['id'],'login'=>$user['login'],'name'=>$user['name'],'role'=>$user['role'])));
}

// ── LOGOUT ───────────────────────────────────────────────────
if ($action === 'logout') {
    if ($method !== 'POST') api_response(array('error'=>'Метод не разрешен'), 405);
    $token = !empty($_SERVER['HTTP_AUTHORIZATION']) ? str_replace('Bearer ','',$_SERVER['HTTP_AUTHORIZATION']) : (!empty($_GET['token']) ? $_GET['token'] : '');
    if (!empty($token)) {
        $stmt = $mysql->prepare("DELETE FROM sessions WHERE token=?");
        $stmt->bind_param("s", $token); $stmt->execute();
    }
    api_response(array('success'=>true));
}

$authUser = getAuthUser($mysql);
if (!$authUser) api_response(array('error'=>'Необходима авторизация'), 401);

function api_photo_upload_response($mysql, $authUser) {
    $result = photo_handle_upload($mysql, $authUser);
    if (isset($result['error'])) {
        api_response(array('error' => $result['error']), isset($result['code']) ? $result['code'] : 400);
    }
    api_response($result);
}

// POST api.php?upload=1&token=...&task_id=... — единая точка загрузки (без PATH_INFO)
if ($method === 'POST' && !empty($_GET['upload'])) {
    api_photo_upload_response($mysql, $authUser);
}

if ($method === 'POST' && isset($_FILES['photo'])) {
    list($uploadTaskId, $uploadExecId) = photo_resolve_ids();
    if ($uploadTaskId || $uploadExecId) {
        api_photo_upload_response($mysql, $authUser);
    }
}

// ── EXECUTIONS (дочерние выполнения) ─────────────────────────
// GET    /executions/{id}          — получить выполнение
// PUT    /executions/{id}          — обновить статус выполнения
// PUT    /executions/{id}/access   — ручное управление доступом
// GET    /executions/{id}/photos   — фото выполнения
// POST   /photos (с execution_id)  — загрузить фото к выполнению
if ($action === 'executions') {

    if ($method === 'GET' && $id !== null && $subAction === null) {
        $stmt = $mysql->prepare(
            "SELECT e.*, t.title, t.description, t.executor, t.required_photos, t.task_type,
                    u.name AS executor_name
             FROM task_executions e
             INNER JOIN tasks t ON t.id = e.parent_task_id
             LEFT JOIN users u ON u.login = t.executor
             WHERE e.id = ?"
        );
        $stmt->bind_param("i", $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) api_response(array('error'=>'Выполнение не найдено'), 404);
        if ($authUser['role']==='worker' && $row['executor']!==$authUser['login'])
            api_response(array('error'=>'Доступ запрещен'), 403);
        api_response($row);
    }

    // PUT /executions/{id}/access
    if ($method === 'PUT' && $id !== null && $subAction === 'access') {
        if ($authUser['role'] !== 'admin') api_response(array('error'=>'Доступ запрещен'), 403);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = array();
        $override = isset($data['access_override']) ? $data['access_override'] : '';
        if (!in_array($override, array('auto','force_open','force_closed')))
            api_response(array('error'=>'Недопустимое значение access_override'), 400);

        $stmt = $mysql->prepare("SELECT id FROM task_executions WHERE id=?");
        $stmt->bind_param("i", $id); $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) api_response(array('error'=>'Выполнение не найдено'), 404);

        $stmt2 = $mysql->prepare("UPDATE task_executions SET access_override=? WHERE id=?");
        $stmt2->bind_param("si", $override, $id);
        if (!$stmt2->execute()) api_response(array('error'=>'Ошибка сохранения access_override'), 500);
        api_response(array('success'=>true, 'access_override'=>$override));
    }

    if ($method === 'PUT' && $id !== null && $subAction === null) {
        $data  = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) $data = array();

        // Admin: обновление access_override (статус опционален)
        if ($authUser['role'] === 'admin' && isset($data['access_override'])) {
            $override = $data['access_override'];
            if (!in_array($override, array('auto','force_open','force_closed')))
                api_response(array('error'=>'Недопустимое значение access_override'), 400);

            $stmt = $mysql->prepare("SELECT id FROM task_executions WHERE id=?");
            $stmt->bind_param("i", $id); $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) api_response(array('error'=>'Выполнение не найдено'), 404);

            $stmt2 = $mysql->prepare("UPDATE task_executions SET access_override=? WHERE id=?");
            $stmt2->bind_param("si", $override, $id);
            if (!$stmt2->execute()) api_response(array('error'=>'Ошибка сохранения access_override'), 500);

            $status = isset($data['status']) ? $data['status'] : '';
            if ($status === '') api_response(array('success'=>true, 'access_override'=>$override));
        }

        if (isset($data['access_override']) && (!isset($data['status']) || $data['status'] === ''))
            api_response(array('error'=>'Доступ запрещен'), 403);

        $allowedStatuses = array('pending','review','completed','rejected');
        $status = isset($data['status']) ? $data['status'] : '';
        if (!in_array($status, $allowedStatuses)) api_response(array('error'=>'Недопустимый статус'), 400);

        // Проверяем доступ
        $stmt = $mysql->prepare("SELECT e.*, t.executor FROM task_executions e INNER JOIN tasks t ON t.id=e.parent_task_id WHERE e.id=?");
        $stmt->bind_param("i", $id); $stmt->execute();
        $exec = $stmt->get_result()->fetch_assoc();
        if (!$exec) api_response(array('error'=>'Выполнение не найдено'), 404);
        if ($authUser['role']==='worker' && $exec['executor']!==$authUser['login'])
            api_response(array('error'=>'Доступ запрещен'), 403);
        if ($authUser['role']==='worker' && !in_array($status, array('review','pending')))
            api_response(array('error'=>'Доступ запрещен'), 403);
        if (in_array($status, array('completed','rejected')) && $authUser['role']!=='admin')
            api_response(array('error'=>'Доступ запрещен'), 403);

        $reason = ($status === 'rejected' && isset($data['rejection_reason']))
            ? $data['rejection_reason'] : null;
        $stmt2 = $mysql->prepare("UPDATE task_executions SET status=?, rejection_reason=? WHERE id=?");
        $stmt2->bind_param("ssi", $status, $reason, $id); $stmt2->execute();
        api_response(array('success'=>true));
    }

    api_response(array('error'=>'Метод не разрешен'), 405);
}

// ── TASKS ────────────────────────────────────────────────────
if ($action === 'tasks') {

    // GET /tasks — список задач
    if ($method === 'GET' && $id === null) {
        if ($authUser['role']==='admin') {
            $result = $mysql->query(
                "SELECT t.*, u.name AS executor_name,
                        (SELECT COUNT(*) FROM task_executions e WHERE e.parent_task_id=t.id) AS executions_count,
                        (SELECT COUNT(*) FROM task_executions e WHERE e.parent_task_id=t.id AND e.status='review') AS review_count
                 FROM tasks t LEFT JOIN users u ON u.login=t.executor ORDER BY t.created_at DESC"
            );
        } else {
            $stmt = $mysql->prepare(
                "SELECT t.*, u.name AS executor_name,
                        (SELECT COUNT(*) FROM task_executions e WHERE e.parent_task_id=t.id) AS executions_count,
                        (SELECT COUNT(*) FROM task_executions e WHERE e.parent_task_id=t.id AND e.status='review') AS review_count
                 FROM tasks t LEFT JOIN users u ON u.login=t.executor
                 WHERE t.executor=? ORDER BY t.created_at DESC"
            );
            $stmt->bind_param("s", $authUser['login']); $stmt->execute();
            $result = $stmt->get_result();
        }
        if (!$result) api_response(array('error'=>'Ошибка запроса'), 500);
        $tasks = array();
        while ($row = $result->fetch_assoc()) $tasks[] = $row;
        api_response($tasks);
    }

    // GET /tasks/{id} — одна задача
    if ($method === 'GET' && $id !== null && $subAction === null) {
        $stmt = $mysql->prepare(
            "SELECT t.*, u.name AS executor_name FROM tasks t LEFT JOIN users u ON u.login=t.executor WHERE t.id=?"
        );
        $stmt->bind_param("i", $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) api_response(array('error'=>'Задача не найдена'), 404);
        if ($authUser['role']==='worker' && $row['executor']!==$authUser['login'])
            api_response(array('error'=>'Доступ запрещен'), 403);
        api_response($row);
    }

    // GET /tasks/{id}/executions — список выполнений периодической задачи
    if ($method === 'GET' && $id !== null && $subAction === 'executions') {
        $stmt = $mysql->prepare(
            "SELECT e.* FROM task_executions e WHERE e.parent_task_id=? ORDER BY e.execution_date ASC"
        );
        $stmt->bind_param("i", $id); $stmt->execute();
        $rows = array();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) $rows[] = $r;
        api_response($rows);
    }

    // POST /tasks — создать задачу
    if ($method === 'POST') {
        if ($authUser['role']!=='admin') api_response(array('error'=>'Доступ запрещен'), 403);
        $data = json_decode(file_get_contents('php://input'), true);

        $title       = isset($data['title'])       ? $data['title']       : '';
        $description = isset($data['description']) ? $data['description'] : '';
        $executor    = isset($data['executor'])     ? $data['executor']    : '';
        $reqPhotos   = isset($data['photosCount'])  ? (int)$data['photosCount'] : 0;
        $taskType    = isset($data['taskType'])     ? $data['taskType']    : 'regular';
        $status      = 'pending';

        if (empty($title)) api_response(array('error'=>'Название обязательно'), 400);
        if (!in_array($taskType, array('regular','periodic'))) $taskType = 'regular';

        if ($taskType === 'periodic') {
            $startDate  = isset($data['startDate'])  ? $data['startDate']  : '';
            $endDate    = isset($data['endDate'])     ? $data['endDate']    : '';
            $openTime   = isset($data['openTime'])    ? $data['openTime']   : '08:00';
            $closeTime  = isset($data['closeTime'])   ? $data['closeTime']  : '20:00';

            if (empty($startDate)||empty($endDate)) api_response(array('error'=>'Укажите период'), 400);

            $stmt = $mysql->prepare(
                "INSERT INTO tasks (title,description,executor,required_photos,photos_count,status,task_type,start_date,end_date,open_time,close_time)
                 VALUES (?,?,?,?,0,?,?,?,?,?,?)"
            );
            if (!$stmt) api_response(array('error'=>'Ошибка prepare: '.$mysql->error), 500);
            $stmt->bind_param("sssissssss", $title,$description,$executor,$reqPhotos,$status,$taskType,$startDate,$endDate,$openTime,$closeTime);
            $stmt->execute();
            $taskId = $mysql->insert_id;

            // Создаём дочерние выполнения для каждого дня периода
            $current = new DateTime($startDate);
            $end     = new DateTime($endDate);
            $end->modify('+1 day');

            $stmtE = $mysql->prepare(
                "INSERT INTO task_executions (parent_task_id,execution_date,open_at,close_at,status) VALUES (?,?,?,?,'pending')"
            );

            while ($current < $end) {
                $dateStr = $current->format('Y-m-d');
                list($openAt, $closeAt) = executionOpenCloseAt($dateStr, $openTime, $closeTime);
                $stmtE->bind_param("isss", $taskId, $dateStr, $openAt, $closeAt);
                $stmtE->execute();
                $current->modify('+1 day');
            }

            api_response(array('success'=>true, 'id'=>$taskId), 201);
        } else {
            $stmt = $mysql->prepare(
                "INSERT INTO tasks (title,description,executor,required_photos,photos_count,status,task_type) VALUES (?,?,?,?,0,?,?)"
            );
            if (!$stmt) api_response(array('error'=>'Ошибка prepare: '.$mysql->error), 500);
            $stmt->bind_param("sssiss", $title,$description,$executor,$reqPhotos,$status,$taskType);
            $stmt->execute();
            api_response(array('success'=>true, 'id'=>$mysql->insert_id), 201);
        }
    }

    // PUT /tasks/{id} — обновить задачу
    if ($method === 'PUT' && $id !== null) {
        $data  = json_decode(file_get_contents('php://input'), true);
        $allowedStatuses = array('pending','review','completed','rejected');
        $keys  = array_keys($data);
        $statusKeys = array_intersect($keys, array('status', 'rejection_reason'));
        $onlyStatusUpdate = (count($statusKeys) === count($keys) && isset($data['status']));

        if ($onlyStatusUpdate) {
            $status = $data['status'];
            if (!in_array($status, $allowedStatuses)) api_response(array('error'=>'Недопустимый статус'), 400);
            if ($authUser['role']==='worker' && !in_array($status, array('review','pending')))
                api_response(array('error'=>'Доступ запрещен'), 403);
            if (in_array($status, array('completed','rejected')) && $authUser['role']!=='admin')
                api_response(array('error'=>'Доступ запрещен'), 403);
            $reason = ($status === 'rejected' && isset($data['rejection_reason']))
                ? $data['rejection_reason'] : null;
            $stmt = $mysql->prepare("UPDATE tasks SET status=?, rejection_reason=? WHERE id=?");
            $stmt->bind_param("ssi", $status, $reason, $id); $stmt->execute();
            api_response(array('success'=>true));
        }

        if ($authUser['role']!=='admin') api_response(array('error'=>'Доступ запрещен'), 403);

        // Полное редактирование
        $title       = isset($data['title'])          ? $data['title']          : '';
        $description = isset($data['description'])    ? $data['description']    : '';
        $executor    = isset($data['executor'])        ? $data['executor']       : '';
        $reqPhotos   = isset($data['requiredPhotos'])  ? (int)$data['requiredPhotos'] : 0;
        $newStatus   = isset($data['status'])          ? $data['status']         : 'pending';
        $rejReason   = ($newStatus === 'rejected' && isset($data['rejection_reason']))
            ? $data['rejection_reason'] : null;

        if (empty($title)) api_response(array('error'=>'Название обязательно'), 400);
        if (!in_array($newStatus, $allowedStatuses)) $newStatus = 'pending';

        // Получаем тип задачи
        $taskRow = $mysql->prepare("SELECT task_type FROM tasks WHERE id=?");
        $taskRow->bind_param("i", $id);
        $taskRow->execute();
        $taskInfo = $taskRow->get_result()->fetch_assoc();
        if (!$taskInfo) api_response(array('error'=>'Задача не найдена'), 404);

        if ($taskInfo['task_type'] === 'periodic' && isset($data['startDate'], $data['endDate'], $data['openTime'], $data['closeTime'])) {
            $startDate = $data['startDate']; // YYYY-MM-DD
            $endDate   = $data['endDate'];
            $openTime  = $data['openTime'];  // HH:MM
            $closeTime = $data['closeTime'];

            if (empty($startDate) || empty($endDate)) api_response(array('error'=>'Укажите период'), 400);
            if ($startDate > $endDate) api_response(array('error'=>'Дата начала не может быть позже даты окончания'), 400);

            // 1. Обновить ParentTask
            $stmt = $mysql->prepare(
                "UPDATE tasks SET title=?,description=?,executor=?,required_photos=?,status=?,rejection_reason=?,start_date=?,end_date=?,open_time=?,close_time=? WHERE id=?"
            );
            if (!$stmt) api_response(array('error'=>'Ошибка prepare: '.$mysql->error), 500);
            $stmt->bind_param("sssissssssi",
                $title, $description, $executor, $reqPhotos, $newStatus, $rejReason,
                $startDate, $endDate, $openTime, $closeTime, $id
            );
            $stmt->execute();

            // Строим множество дат нового периода
            $newDates = array();
            $cur = new DateTime($startDate);
            $end = new DateTime($endDate);
            while ($cur <= $end) {
                $newDates[] = $cur->format('Y-m-d');
                $cur->modify('+1 day');
            }
            $newDatesSet = array_flip($newDates);

            // 2. Получаем существующие выполнения
            $sel = $mysql->prepare("SELECT id, execution_date FROM task_executions WHERE parent_task_id=?");
            $sel->bind_param("i", $id);
            $sel->execute();
            $existingRows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);

            $existingDatesSet = array(); // date => execution_id
            foreach ($existingRows as $row) {
                $existingDatesSet[$row['execution_date']] = $row['id'];
            }

            // 2. Удалить выполнения вне нового периода
            foreach ($existingRows as $row) {
                if (!isset($newDatesSet[$row['execution_date']])) {
                    $del = $mysql->prepare("DELETE FROM task_executions WHERE id=?");
                    $del->bind_param("i", $row['id']);
                    $del->execute();
                }
            }

            // 3. Создать выполнения для новых дат + 4. Обновить open_at/close_at для существующих
            foreach ($newDates as $dateStr) {
                list($openAt, $closeAt) = executionOpenCloseAt($dateStr, $openTime, $closeTime);

                if (isset($existingDatesSet[$dateStr])) {
                    // 4. Обновить open_at/close_at существующего
                    $upd = $mysql->prepare("UPDATE task_executions SET open_at=?, close_at=? WHERE id=?");
                    $upd->bind_param("ssi", $openAt, $closeAt, $existingDatesSet[$dateStr]);
                    $upd->execute();
                } else {
                    // 3. Создать новое выполнение
                    $ins = $mysql->prepare(
                        "INSERT INTO task_executions (parent_task_id, execution_date, open_at, close_at, status) VALUES (?, ?, ?, ?, 'pending')"
                    );
                    $ins->bind_param("isss", $id, $dateStr, $openAt, $closeAt);
                    $ins->execute();
                }
            }

            api_response(array('success' => true));
        }

        // Обычная задача (или периодическая без смены периода)
        $stmt = $mysql->prepare(
            "UPDATE tasks SET title=?,description=?,executor=?,required_photos=?,status=?,rejection_reason=? WHERE id=?"
        );
        if (!$stmt) api_response(array('error'=>'Ошибка prepare: '.$mysql->error), 500);
        $stmt->bind_param("sssissi", $title,$description,$executor,$reqPhotos,$newStatus,$rejReason,$id);
        $stmt->execute();
        api_response(array('success'=>true));
    }

    // DELETE /tasks/{id}
    if ($method === 'DELETE' && $id !== null) {
        if ($authUser['role']!=='admin') api_response(array('error'=>'Доступ запрещен'), 403);
        $stmt = $mysql->prepare("DELETE FROM tasks WHERE id=?");
        if (!$stmt) api_response(array('error'=>'Ошибка prepare'), 500);
        $stmt->bind_param("i", $id); $stmt->execute();
        api_response(array('success'=>true));
    }

    api_response(array('error'=>'Метод не разрешен'), 405);
}

// ── PHOTOS ───────────────────────────────────────────────────
elseif ($action === 'photos') {

    // GET /photos/{task_id} — фото обычной задачи
    if ($method === 'GET' && $id !== null) {
        $stmt = $mysql->prepare("SELECT * FROM task_photos WHERE task_id=? AND execution_id IS NULL");
        $stmt->bind_param("i", $id); $stmt->execute();
        $photos = array();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) $photos[] = $r;
        syncTaskPhotosCount($mysql, (int)$id);
        api_response($photos);
    }

    if ($method === 'POST') {
        api_photo_upload_response($mysql, $authUser);
    }

    if ($method === 'DELETE' && $id !== null) {
        $stmt = $mysql->prepare("SELECT url,task_id,execution_id FROM task_photos WHERE id=?");
        $stmt->bind_param("i",$id); $stmt->execute();
        $photo = $stmt->get_result()->fetch_assoc();
        if ($photo) {
            $f = __DIR__ . '/uploads/' . basename($photo['url']);
            if (file_exists($f)) unlink($f);
            $stmt2 = $mysql->prepare("DELETE FROM task_photos WHERE id=?");
            $stmt2->bind_param("i",$id); $stmt2->execute();
            if ($photo['execution_id']) {
                syncExecutionPhotosCount($mysql, (int)$photo['execution_id']);
            } else {
                syncTaskPhotosCount($mysql, (int)$photo['task_id']);
            }
        }
        api_response(array('success'=>true));
    }

    // GET фото выполнения: /photos/execution/{execId}
    api_response(array('error'=>'Метод не разрешен'), 405);
}

// ── PHOTOS для execution (отдельный маршрут) ─────────────────
// GET /execution-photos/{execId}
elseif ($action === 'execution-photos') {
    if ($method === 'GET' && $id !== null) {
        $execId = (int)$id;
        $stmt = $mysql->prepare("SELECT id, task_id, execution_id, url FROM task_photos WHERE execution_id=?");
        if (!$stmt) api_response(array('error'=>'SQL: ' . $mysql->error), 500);
        $stmt->bind_param("i", $execId);
        $stmt->execute();
        $photos = array();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) $photos[] = $r;
        syncExecutionPhotosCount($mysql, $execId);
        api_response($photos);
    }
    api_response(array('error'=>'Метод не разрешен'), 405);
}

// ── USERS ────────────────────────────────────────────────────
elseif ($action === 'users') {
    if ($method === 'GET') {
        $result = $mysql->query("SELECT id,login,name,role FROM users ORDER BY id ASC");
        $users = array(); while ($r = $result->fetch_assoc()) $users[] = $r;
        api_response($users);
    }
    if ($method === 'POST') {
        if ($authUser['role']!=='admin') api_response(array('error'=>'Доступ запрещен'), 403);
        $data = json_decode(file_get_contents('php://input'), true);
        $login = isset($data['login']) ? trim($data['login']) : '';
        $name  = isset($data['name'])  ? trim($data['name'])  : '';
        $pass  = isset($data['password']) ? $data['password'] : '';
        $role  = isset($data['role'])  ? $data['role']  : 'worker';
        if (empty($login)||empty($name)||empty($pass)) api_response(array('error'=>'Логин, имя и пароль обязательны'), 400);
        if (!in_array($role, array('admin','worker'))) $role = 'worker';
        $stmt = $mysql->prepare("SELECT id FROM users WHERE login=?");
        $stmt->bind_param("s",$login); $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) api_response(array('error'=>'Пользователь с таким логином уже существует'), 400);
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt2 = $mysql->prepare("INSERT INTO users (login,name,password,role) VALUES (?,?,?,?)");
        if (!$stmt2) api_response(array('error'=>'Ошибка prepare: '.$mysql->error), 500);
        $stmt2->bind_param("ssss",$login,$name,$hash,$role); $stmt2->execute();
        api_response(array('success'=>true,'id'=>$mysql->insert_id), 201);
    }
    if ($method === 'PUT' && $id !== null) {
        if ($authUser['role']!=='admin') api_response(array('error'=>'Доступ запрещен'), 403);
        $data = json_decode(file_get_contents('php://input'), true);
        $login = isset($data['login']) ? trim($data['login']) : '';
        $name  = isset($data['name'])  ? trim($data['name'])  : '';
        $pass  = isset($data['password']) ? $data['password'] : '';
        $role  = isset($data['role'])  ? $data['role']  : 'worker';
        if (empty($login) || empty($name)) api_response(array('error'=>'Логин и имя обязательны'), 400);
        if (!in_array($role, array('admin','worker'))) $role = 'worker';

        $stmt = $mysql->prepare("SELECT id FROM users WHERE id=?");
        $stmt->bind_param("i", $id); $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) api_response(array('error'=>'Пользователь не найден'), 404);

        $stmt = $mysql->prepare("SELECT id FROM users WHERE login=? AND id!=?");
        $stmt->bind_param("si", $login, $id); $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) api_response(array('error'=>'Пользователь с таким логином уже существует'), 400);

        if (!empty($pass)) {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt2 = $mysql->prepare("UPDATE users SET login=?, name=?, role=?, password=? WHERE id=?");
            if (!$stmt2) api_response(array('error'=>'Ошибка prepare: '.$mysql->error), 500);
            $stmt2->bind_param("ssssi", $login, $name, $role, $hash, $id); $stmt2->execute();
        } else {
            $stmt2 = $mysql->prepare("UPDATE users SET login=?, name=?, role=? WHERE id=?");
            if (!$stmt2) api_response(array('error'=>'Ошибка prepare: '.$mysql->error), 500);
            $stmt2->bind_param("sssi", $login, $name, $role, $id); $stmt2->execute();
        }
        api_response(array('success'=>true));
    }
    if ($method === 'DELETE' && $id !== null) {
        if ($authUser['role']!=='admin') api_response(array('error'=>'Доступ запрещен'), 403);
        if ($id==$authUser['id']) api_response(array('error'=>'Нельзя удалить себя'), 400);
        $stmt = $mysql->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i",$id); $stmt->execute();
        api_response(array('success'=>true));
    }
    api_response(array('error'=>'Метод не разрешен'), 405);
}

else {
    api_response(array('error'=>'Ресурс не найден'), 404);
}
?>