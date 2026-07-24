<?php
// ==============================================
// API AUTH - dengan fitur upload foto profil
// ==============================================

require_once '../config/database.php';
setCorsHeaders();

$conn   = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Helper: ambil kelas dominan mahasiswa dari enrollment
function getKelasUser($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT kelas, COUNT(*) as total 
        FROM enrollment 
        WHERE mahasiswa_id = ? AND kelas IS NOT NULL AND kelas != ''
        GROUP BY kelas 
        ORDER BY total DESC 
        LIMIT 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0 ? $result->fetch_assoc()['kelas'] : null;
}

// ============================================================
// LOGIN
// ============================================================
if ($method === 'POST' && $action === 'login') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $nim_nidn = trim($body['nim_nidn'] ?? '');
    $password = trim($body['password'] ?? '');

    if (empty($nim_nidn) || empty($password)) {
        sendResponse('error', 'NIM/NIDN dan password wajib diisi', null, 400);
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE nim_nidn = ?");
    $stmt->bind_param("s", $nim_nidn);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        sendResponse('error', 'NIM/NIDN tidak ditemukan', null, 404);
    }

    $user = $result->fetch_assoc();

    if (!password_verify($password, $user['password'])) {
        sendResponse('error', 'Password salah', null, 401);
    }

    $token      = bin2hex(random_bytes(32));
    $expired_at = date('Y-m-d H:i:s', strtotime('+7 days'));

    $delStmt = $conn->prepare("DELETE FROM user_tokens WHERE user_id = ?");
    $delStmt->bind_param("i", $user['id']);
    $delStmt->execute();

    $tokenStmt = $conn->prepare("INSERT INTO user_tokens (user_id, token, expired_at) VALUES (?, ?, ?)");
    $tokenStmt->bind_param("iss", $user['id'], $token, $expired_at);
    $tokenStmt->execute();

    unset($user['password']);
    // Sertakan kelas saat login
    $user['kelas'] = getKelasUser($conn, $user['id']);

    sendResponse('success', 'Login berhasil', [
        'token'      => $token,
        'expired_at' => $expired_at,
        'user'       => $user,
    ]);
}

// ============================================================
// GET PROFILE
// ============================================================
if ($method === 'GET' && $action === 'profile') {
    $user = validateToken($conn);
    unset($user['password']);
    // Sertakan kelas dari enrollment
    $user['kelas'] = getKelasUser($conn, $user['id']);
    sendResponse('success', 'Data profil', $user);
}

// ============================================================
// UPLOAD FOTO PROFIL
// ============================================================
if ($method === 'POST' && $action === 'upload_foto') {
    $user = validateToken($conn);
    
    $body = json_decode(file_get_contents('php://input'), true);
    $foto = $body['foto'] ?? '';

    if (empty($foto)) {
        sendResponse('error', 'Data foto tidak boleh kosong', null, 400);
    }

    // Validasi format base64 data URL
    if (!preg_match('/^data:image\/(jpeg|png|jpg);base64,/', $foto)) {
        sendResponse('error', 'Format foto tidak valid', null, 400);
    }

    // Batasi ukuran (max ~1MB base64 = ~750KB file)
    if (strlen($foto) > 1400000) {
        sendResponse('error', 'Ukuran foto terlalu besar (max 1MB)', null, 400);
    }

    $stmt = $conn->prepare("UPDATE users SET foto = ? WHERE id = ?");
    $stmt->bind_param("si", $foto, $user['id']);

    if ($stmt->execute()) {
        $getStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $getStmt->bind_param("i", $user['id']);
        $getStmt->execute();
        $updatedUser = $getStmt->get_result()->fetch_assoc();
        unset($updatedUser['password']);
        $updatedUser['kelas'] = getKelasUser($conn, $updatedUser['id']);
        sendResponse('success', 'Foto profil berhasil diperbarui', $updatedUser);
    } else {
        sendResponse('error', 'Gagal menyimpan foto: ' . $conn->error, null, 500);
    }
}

sendResponse('error', 'Endpoint tidak ditemukan', null, 404);
