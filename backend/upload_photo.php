<?php
error_reporting(0);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => 'Метод не разрешен'), JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/photo_lib.php';

function up_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($mysql)) up_json(array('error' => 'Ошибка подключения к базе данных'), 500);

photo_run_migrations($mysql);

$token = '';
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
} elseif (!empty($_GET['token'])) {
    $token = $_GET['token'];
} elseif (!empty($_POST['token'])) {
    $token = $_POST['token'];
}
if (empty($token)) up_json(array('error' => 'Необходима авторизация'), 401);

$token = trim($token);
$stmt = $mysql->prepare(
    'SELECT u.id,u.login,u.name,u.role FROM users u
     INNER JOIN sessions s ON s.user_id=u.id
     WHERE s.token=? AND s.expires_at>NOW() LIMIT 1'
);
if (!$stmt) up_json(array('error' => 'Ошибка авторизации'), 500);
$stmt->bind_param('s', $token);
$stmt->execute();
$authUser = $stmt->get_result()->fetch_assoc();
if (!$authUser) up_json(array('error' => 'Необходима авторизация'), 401);

$result = photo_handle_upload($mysql, $authUser);
if (isset($result['error'])) {
    up_json(array('error' => $result['error']), isset($result['code']) ? $result['code'] : 400);
}
up_json($result);
