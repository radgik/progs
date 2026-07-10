<?php

function photo_run_migrations($mysql) {
    $chk = $mysql->query("SHOW COLUMNS FROM task_photos LIKE 'execution_id'");
    if ($chk && $chk->num_rows === 0) {
        $mysql->query('ALTER TABLE task_photos ADD COLUMN execution_id INT DEFAULT NULL');
        $mysql->query('ALTER TABLE task_photos ADD INDEX idx_execution_id (execution_id)');
    }
    $chk = $mysql->query("SHOW COLUMNS FROM task_executions LIKE 'access_override'");
    if ($chk && $chk->num_rows === 0) {
        $mysql->query("ALTER TABLE task_executions ADD COLUMN access_override VARCHAR(20) NOT NULL DEFAULT 'auto'");
    }
}

function photo_execution_is_open($exec) {
    $override = isset($exec['access_override']) ? $exec['access_override'] : 'auto';
    if ($override === 'force_open') return true;
    if ($override === 'force_closed') return false;
    $now = time();
    return $now >= strtotime($exec['open_at']) && $now <= strtotime($exec['close_at']);
}

function photo_sync_task_count($mysql, $taskId) {
    $stmt = $mysql->prepare('SELECT COUNT(*) AS cnt FROM task_photos WHERE task_id=? AND execution_id IS NULL');
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $cnt = $row ? (int)$row['cnt'] : 0;
    $stmt2 = $mysql->prepare('UPDATE tasks SET photos_count=? WHERE id=?');
    $stmt2->bind_param('ii', $cnt, $taskId);
    $stmt2->execute();
}

function photo_sync_exec_count($mysql, $execId) {
    $stmt = $mysql->prepare('SELECT COUNT(*) AS cnt FROM task_photos WHERE execution_id=?');
    $stmt->bind_param('i', $execId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $cnt = $row ? (int)$row['cnt'] : 0;
    $stmt2 = $mysql->prepare('UPDATE task_executions SET photos_count=? WHERE id=?');
    $stmt2->bind_param('ii', $cnt, $execId);
    $stmt2->execute();
}

function photo_resolve_ids($jsonData = null) {
    $taskId = null;
    $execId = null;
    if (!empty($_GET['task_id'])) $taskId = (int)$_GET['task_id'];
    elseif (!empty($_POST['task_id'])) $taskId = (int)$_POST['task_id'];
    if (!empty($_GET['execution_id'])) $execId = (int)$_GET['execution_id'];
    elseif (!empty($_POST['execution_id'])) $execId = (int)$_POST['execution_id'];
    if (is_array($jsonData)) {
        if (!$taskId && !empty($jsonData['task_id'])) $taskId = (int)$jsonData['task_id'];
        if (!$execId && !empty($jsonData['execution_id'])) $execId = (int)$jsonData['execution_id'];
    }
    return array($taskId, $execId);
}

function photo_public_url($filename) {
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/uploads/' . $filename;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $path;
}

function photo_check_limit($mysql, $authUser, $taskId, $execId) {
    if ($execId) {
        $stmt = $mysql->prepare(
            'SELECT e.open_at, e.close_at, e.access_override, t.required_photos,
                    (SELECT COUNT(*) FROM task_photos p WHERE p.execution_id=e.id) AS cnt
             FROM task_executions e INNER JOIN tasks t ON t.id=e.parent_task_id WHERE e.id=?'
        );
        $stmt->bind_param('i', $execId);
        $stmt->execute();
        $d = $stmt->get_result()->fetch_assoc();
        if (!$d) return array('error' => 'Выполнение не найдено', 'code' => 404);
        if (!photo_execution_is_open($d) && $authUser['role'] !== 'admin') {
            return array('error' => 'Период закрыт. Загрузка фото недоступна.', 'code' => 403);
        }
        if ((int)$d['cnt'] >= (int)$d['required_photos']) {
            return array('error' => 'Достигнут лимит фотографий', 'code' => 400);
        }
        return null;
    }

    $stmt = $mysql->prepare(
        'SELECT t.required_photos,
                (SELECT COUNT(*) FROM task_photos p WHERE p.task_id=t.id AND p.execution_id IS NULL) AS cnt
         FROM tasks t WHERE t.id=?'
    );
    $stmt->bind_param('i', $taskId);
    $stmt->execute();
    $d = $stmt->get_result()->fetch_assoc();
    if (!$d) return array('error' => 'Задача не найдена', 'code' => 404);
    if ((int)$d['cnt'] >= (int)$d['required_photos']) {
        return array('error' => 'Достигнут лимит фотографий', 'code' => 400);
    }
    return null;
}

function photo_save_record($mysql, $taskId, $execId, $url) {
    if ($execId) {
        $s2 = $mysql->prepare('SELECT parent_task_id FROM task_executions WHERE id=?');
        $s2->bind_param('i', $execId);
        $s2->execute();
        $r2 = $s2->get_result()->fetch_assoc();
        $actualTaskId = $r2 ? (int)$r2['parent_task_id'] : 0;
        $stmt = $mysql->prepare('INSERT INTO task_photos (task_id,execution_id,url) VALUES (?,?,?)');
        $stmt->bind_param('iis', $actualTaskId, $execId, $url);
        $stmt->execute();
        $photoId = $mysql->insert_id;
        photo_sync_exec_count($mysql, $execId);
        return $photoId;
    }

    $stmt = $mysql->prepare('INSERT INTO task_photos (task_id,url) VALUES (?,?)');
    $stmt->bind_param('is', $taskId, $url);
    $stmt->execute();
    $photoId = $mysql->insert_id;
    photo_sync_task_count($mysql, $taskId);
    return $photoId;
}

function photo_detect_ext($binary, $fallbackExt = '') {
    $info = @getimagesizefromstring($binary);
    if ($info !== false) {
        $ext = image_type_to_extension($info[2], false);
        if ($ext === 'jpeg') $ext = 'jpg';
        if (in_array($ext, array('jpg', 'png', 'webp'))) return $ext;
    }
    $ext = strtolower($fallbackExt);
    if ($ext === 'jpeg') $ext = 'jpg';
    return $ext;
}

function photo_save_binary($mysql, $authUser, $taskId, $execId, $binary, $ext) {
    if (!$taskId && !$execId) {
        return array('error' => 'Укажите task_id или execution_id', 'code' => 400);
    }

    $limitErr = photo_check_limit($mysql, $authUser, $taskId, $execId);
    if ($limitErr) return $limitErr;

    $ext = photo_detect_ext($binary, $ext);
    if (!in_array($ext, array('jpg', 'png', 'webp'))) {
        return array('error' => 'Неверный тип файла', 'code' => 400);
    }
    if (strlen($binary) > 5 * 1024 * 1024) {
        return array('error' => 'Файл слишком большой', 'code' => 400);
    }

    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return array('error' => 'Не удалось создать папку uploads', 'code' => 500);
    }

    $filename = uniqid('ph_', true) . '.' . $ext;
    if (file_put_contents($uploadDir . $filename, $binary) === false) {
        return array('error' => 'Ошибка сохранения файла', 'code' => 500);
    }

    $url = photo_public_url($filename);
    $photoId = photo_save_record($mysql, $taskId, $execId, $url);
    return array('success' => true, 'id' => $photoId, 'url' => $url);
}

function photo_handle_upload($mysql, $authUser) {
    list($taskId, $execId) = photo_resolve_ids();

    $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($contentType, 'application/json') !== false) {
        $jsonData = json_decode(file_get_contents('php://input'), true);
        if (!is_array($jsonData)) {
            return array('error' => 'Некорректный JSON', 'code' => 400);
        }
        list($taskId, $execId) = photo_resolve_ids($jsonData);
        $b64 = isset($jsonData['photo_base64']) ? $jsonData['photo_base64'] : '';
        if (empty($b64)) {
            return array('error' => 'Нет файла', 'code' => 400);
        }
        $comma = strpos($b64, ',');
        if ($comma !== false) $b64 = substr($b64, $comma + 1);
        $binary = base64_decode($b64, true);
        if ($binary === false) {
            return array('error' => 'Некорректные данные файла', 'code' => 400);
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            return array('error' => 'Неверный тип файла', 'code' => 400);
        }
        $ext = image_type_to_extension($info[2], false);
        return photo_save_binary($mysql, $authUser, $taskId, $execId, $binary, $ext);
    }

    if (!isset($_FILES['photo'])) {
        return array('error' => 'Файл не получен. Используйте поле photo.', 'code' => 400);
    }

    $file = $_FILES['photo'];
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Ошибка передачи файла';
        if (isset($file['error']) && in_array($file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE))) {
            $msg = 'Файл слишком большой (лимит сервера)';
        }
        return array('error' => $msg, 'code' => 400);
    }

    $allowedTypes = array('image/jpeg', 'image/png', 'image/pjpeg', 'image/webp');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $typeOk = in_array($file['type'], $allowedTypes) || in_array($ext, array('jpg', 'jpeg', 'png', 'webp'));
    if (!$typeOk) {
        return array('error' => 'Неверный тип файла', 'code' => 400);
    }

    $binary = file_get_contents($file['tmp_name']);
    if ($binary === false) {
        return array('error' => 'Не удалось прочитать файл', 'code' => 400);
    }

    return photo_save_binary($mysql, $authUser, $taskId, $execId, $binary, $ext);
}

function photo_parse_route($requestUri) {
    $path = parse_url($requestUri, PHP_URL_PATH);
    $action = '';
    $id = null;
    $subAction = null;

    if (preg_match('#/api\.php(?:/(.*))?$#', $path, $m)) {
        $tail = isset($m[1]) ? trim($m[1], '/') : '';
        if ($tail !== '') {
            $parts = explode('/', $tail);
            $action = $parts[0];
            $id = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
            $subAction = isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null;
            return array($action, $id, $subAction);
        }
    }

    $pathParts = explode('/', trim($path, '/'));
    foreach ($pathParts as $i => $part) {
        if (in_array($part, array('tasks', 'users', 'photos', 'login', 'logout', 'executions', 'execution-photos'))) {
            $action = $part;
            $id = isset($pathParts[$i + 1]) && $pathParts[$i + 1] !== '' ? $pathParts[$i + 1] : null;
            $subAction = isset($pathParts[$i + 2]) && $pathParts[$i + 2] !== '' ? $pathParts[$i + 2] : null;
            break;
        }
    }

    if ($action === '' && !empty($_GET['action'])) {
        $action = $_GET['action'];
        $id = isset($_GET['id']) ? $_GET['id'] : null;
    }

    return array($action, $id, $subAction);
}
