<?php
// ==============================================
// CONFIG: Railway Database Configuration
// File: backend/config/database.php
// ==============================================

// Set timezone ke WIB agar kalkulasi waktu absen akurat
date_default_timezone_set('Asia/Jakarta');

define('DB_HOST', getenv('MYSQLHOST') ?: 'sakura.proxy.rlwy.net');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'EgIrTomPGcrWKYQchHueFiUSQNLLSJLB');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'railway');
define('DB_PORT', intval(getenv('MYSQLPORT') ?: 14740));

// Koneksi ke database
function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    
    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode([
            'status' => 'error',
            'message' => 'Koneksi database gagal: ' . $conn->connect_error
        ]));
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

// Header CORS untuk Flutter
function setCorsHeaders() {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Content-Type: application/json; charset=UTF-8");
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

// Response helper
function sendResponse($status, $message, $data = null, $code = 200) {
    http_response_code($code);
    $response = [
        'status'  => $status,
        'message' => $message,
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

function getAuthorizationHeader() {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                return $value;
            }
        }
    }

    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    return '';
}

// Validasi token
function validateToken($conn) {
    $authHeader = getAuthorizationHeader();

    if (empty($authHeader) || stripos($authHeader, 'Bearer ') !== 0) {
        sendResponse('error', 'Token tidak ditemukan', null, 401);
    }

    $token = trim(substr($authHeader, 7));

    if (empty($token)) {
        sendResponse('error', 'Token kosong', null, 401);
    }

    $stmt = $conn->prepare("SELECT u.* FROM users u 
                            INNER JOIN user_tokens t ON u.id = t.user_id 
                            WHERE t.token = ? AND t.expired_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        sendResponse('error', 'Token tidak valid atau sudah expired', null, 401);
    }

    return $result->fetch_assoc();
}