<?php
// ==============================================
// API MATA KULIAH
// File: backend/api/mata_kuliah.php
// ==============================================

require_once '../config/database.php';
setCorsHeaders();

$conn   = getConnection();
$user   = validateToken($conn);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ============================================================
// GET: Daftar mata kuliah
// ============================================================
if ($method === 'GET' && $action === 'list') {
    if ($user['role'] === 'mahasiswa') {
        // Mahasiswa: tampilkan matkul yang dienroll
        $stmt = $conn->prepare("SELECT mk.*, u.nama AS dosen_nama,
                                COUNT(DISTINCT s.id) AS total_pertemuan
                                FROM mata_kuliah mk
                                JOIN enrollment e ON e.mata_kuliah_id = mk.id
                                JOIN users u ON u.id = mk.dosen_id
                                LEFT JOIN sesi_absensi s ON s.mata_kuliah_id = mk.id
                                WHERE e.mahasiswa_id = ?
                                GROUP BY mk.id");
        $stmt->bind_param("i", $user['id']);
    } else {
        // Dosen/Admin: tampilkan semua matkul mereka
        $dosenId = $user['role'] === 'admin' ? 0 : $user['id'];
        if ($dosenId > 0) {
            $stmt = $conn->prepare("SELECT mk.*, u.nama AS dosen_nama,
                                    COUNT(DISTINCT e.id) AS total_mahasiswa,
                                    COUNT(DISTINCT s.id) AS total_pertemuan
                                    FROM mata_kuliah mk
                                    JOIN users u ON u.id = mk.dosen_id
                                    LEFT JOIN enrollment e ON e.mata_kuliah_id = mk.id
                                    LEFT JOIN sesi_absensi s ON s.mata_kuliah_id = mk.id
                                    WHERE mk.dosen_id = ?
                                    GROUP BY mk.id");
            $stmt->bind_param("i", $dosenId);
        } else {
            $stmt = $conn->prepare("SELECT mk.*, u.nama AS dosen_nama,
                                    COUNT(DISTINCT e.id) AS total_mahasiswa,
                                    COUNT(DISTINCT s.id) AS total_pertemuan
                                    FROM mata_kuliah mk
                                    JOIN users u ON u.id = mk.dosen_id
                                    LEFT JOIN enrollment e ON e.mata_kuliah_id = mk.id
                                    LEFT JOIN sesi_absensi s ON s.mata_kuliah_id = mk.id
                                    GROUP BY mk.id");
        }
    }
    
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    sendResponse('success', 'Daftar mata kuliah', $result);
}

// ============================================================
// POST: Tambah mata kuliah (dosen/admin)
// ============================================================
if ($method === 'POST' && $action === 'tambah') {
    if (!in_array($user['role'], ['dosen', 'admin'])) {
        sendResponse('error', 'Akses ditolak', null, 403);
    }
    
    $body       = json_decode(file_get_contents('php://input'), true);
    $kode_mk    = strtoupper(trim($body['kode_mk'] ?? ''));
    $nama_mk    = trim($body['nama_mk'] ?? '');
    $sks        = (int)($body['sks'] ?? 2);
    $semester   = (int)($body['semester'] ?? 1);
    $dosen_id   = $user['role'] === 'admin' ? (int)($body['dosen_id'] ?? $user['id']) : $user['id'];
    
    if (empty($kode_mk) || empty($nama_mk)) {
        sendResponse('error', 'Kode MK dan nama MK wajib diisi', null, 400);
    }
    
    $stmt = $conn->prepare("INSERT INTO mata_kuliah (kode_mk, nama_mk, sks, semester, dosen_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiii", $kode_mk, $nama_mk, $sks, $semester, $dosen_id);
    
    if ($stmt->execute()) {
        sendResponse('success', 'Mata kuliah berhasil ditambahkan', ['id' => $conn->insert_id], 201);
    } else {
        sendResponse('error', 'Kode MK sudah digunakan', null, 409);
    }
}

// ============================================================
// DELETE: Hapus mata kuliah
// ============================================================
if ($method === 'DELETE' && $action === 'hapus') {
    if (!in_array($user['role'], ['dosen', 'admin'])) {
        sendResponse('error', 'Akses ditolak', null, 403);
    }
    
    $mk_id = (int)($_GET['id'] ?? 0);
    $stmt  = $conn->prepare("DELETE FROM mata_kuliah WHERE id = ? AND dosen_id = ?");
    $stmt->bind_param("ii", $mk_id, $user['id']);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        sendResponse('success', 'Mata kuliah berhasil dihapus');
    } else {
        sendResponse('error', 'Mata kuliah tidak ditemukan atau bukan milik Anda', null, 404);
    }
}

// ============================================================
// POST: Enroll mahasiswa ke mata kuliah
// ============================================================
if ($method === 'POST' && $action === 'enroll') {
    $body          = json_decode(file_get_contents('php://input'), true);
    $mk_id         = (int)($body['mata_kuliah_id'] ?? 0);
    $kelas         = strtoupper(trim($body['kelas'] ?? ''));
    $mahasiswa_id  = $user['role'] === 'mahasiswa' ? $user['id'] : (int)($body['mahasiswa_id'] ?? 0);

    if ($mk_id === 0)   sendResponse('error', 'mata_kuliah_id wajib diisi', null, 400);
    if (empty($kelas))  sendResponse('error', 'kelas wajib diisi', null, 400);
    if ($mahasiswa_id === 0) sendResponse('error', 'mahasiswa_id wajib diisi', null, 400);

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO enrollment (mahasiswa_id, mata_kuliah_id, kelas) VALUES (?, ?, ?)"
    );
    $stmt->bind_param("iis", $mahasiswa_id, $mk_id, $kelas);

    if ($stmt->execute()) {
        sendResponse('success', 'Berhasil mendaftar ke mata kuliah', ['kelas' => $kelas]);
    } else {
        sendResponse('error', 'Gagal mendaftar', null, 500);
    }
}

// ============================================================
// GET: Daftar kelas per mata kuliah (untuk dropdown dosen)
// GET /mata_kuliah.php?action=get_kelas&mk_id={id}
// ============================================================
if ($method === 'GET' && $action === 'get_kelas') {
    if (!in_array($user['role'], ['dosen', 'admin'])) {
        sendResponse('error', 'Akses ditolak', null, 403);
    }

    $mk_id = (int)($_GET['mk_id'] ?? 0);
    if ($mk_id === 0) sendResponse('error', 'mk_id wajib diisi', null, 400);

    // Verifikasi matkul milik dosen ini
    if ($user['role'] === 'dosen') {
        $chk = $conn->prepare("SELECT id FROM mata_kuliah WHERE id = ? AND dosen_id = ?");
        $chk->bind_param("ii", $mk_id, $user['id']);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            sendResponse('error', 'Mata kuliah tidak ditemukan', null, 404);
        }
    }

    $stmt = $conn->prepare("
        SELECT kelas, COUNT(mahasiswa_id) AS jumlah_mahasiswa
        FROM enrollment
        WHERE mata_kuliah_id = ?
        GROUP BY kelas
        ORDER BY kelas ASC
    ");
    $stmt->bind_param("i", $mk_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) $r['jumlah_mahasiswa'] = (int)$r['jumlah_mahasiswa'];
    unset($r);

    sendResponse('success', 'Daftar kelas', $rows);
}

// ============================================================
// GET: Daftar sesi absensi per mata kuliah
// ============================================================
if ($method === 'GET' && $action === 'sesi') {
    $mk_id = (int)($_GET['mk_id'] ?? 0);
    
    $stmt = $conn->prepare("SELECT s.*, 
                            COUNT(a.id) AS jumlah_hadir
                            FROM sesi_absensi s
                            LEFT JOIN absensi a ON a.sesi_id = s.id
                            WHERE s.mata_kuliah_id = ?
                            GROUP BY s.id
                            ORDER BY s.tanggal DESC, s.jam_mulai DESC");
    $stmt->bind_param("i", $mk_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    sendResponse('success', 'Daftar sesi', $result);
}

sendResponse('error', 'Endpoint tidak ditemukan', null, 404);
