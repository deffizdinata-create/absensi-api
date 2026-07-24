<?php
// ==============================================
// API ABSENSI: Sesi & Record Absensi
// File: backend/api/absensi.php
// ==============================================

require_once '../config/database.php';
setCorsHeaders();

$conn   = getConnection();
$user   = validateToken($conn);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ============================================================
// DOSEN: Buat sesi absensi baru
// POST /absensi.php?action=buat_sesi
// ============================================================
if ($method === 'POST' && $action === 'buat_sesi') {
    if (!in_array($user['role'], ['dosen', 'admin'])) {
        sendResponse('error', 'Akses ditolak', null, 403);
    }
    
    $body           = json_decode(file_get_contents('php://input'), true);
    $mk_id          = (int)($body['mata_kuliah_id'] ?? 0);
    $kelas          = strtoupper(trim($body['kelas'] ?? ''));
    $tanggal        = $body['tanggal'] ?? date('Y-m-d');
    $jam_mulai      = $body['jam_mulai'] ?? '08:00:00';
    $jam_selesai    = $body['jam_selesai'] ?? '10:00:00';
    $pertemuan_ke   = (int)($body['pertemuan_ke'] ?? 1);
    $materi         = $body['materi'] ?? '';

    if ($mk_id === 0) {
        sendResponse('error', 'mata_kuliah_id wajib diisi', null, 400);
    }
    if (empty($kelas)) {
        sendResponse('error', 'kelas wajib diisi', null, 400);
    }

    // Pastikan kelas ini ada di enrollment untuk matkul tersebut
    $cekKelas = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM enrollment WHERE mata_kuliah_id = ? AND kelas = ?"
    );
    $cekKelas->bind_param("is", $mk_id, $kelas);
    $cekKelas->execute();
    if ((int)$cekKelas->get_result()->fetch_assoc()['cnt'] === 0) {
        sendResponse('error', 'Kelas tidak ditemukan untuk mata kuliah ini', null, 400);
    }

    $kode_absen = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $qr_token   = bin2hex(random_bytes(16));

    $stmt = $conn->prepare("INSERT INTO sesi_absensi
        (mata_kuliah_id, kelas, tanggal, jam_mulai, jam_selesai, kode_absen, qr_token, pertemuan_ke, materi)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssis", $mk_id, $kelas, $tanggal, $jam_mulai, $jam_selesai,
                      $kode_absen, $qr_token, $pertemuan_ke, $materi);

    if ($stmt->execute()) {
        $sesi_id = $conn->insert_id;
        sendResponse('success', 'Sesi absensi berhasil dibuat', [
            'sesi_id'     => $sesi_id,
            'kode_absen'  => $kode_absen,
            'qr_token'    => $qr_token,
            'qr_data'     => "ABSENSI|{$sesi_id}|{$qr_token}",
        ], 201);
    } else {
        sendResponse('error', 'Gagal membuat sesi: ' . $conn->error, null, 500);
    }
}

// ============================================================
// DOSEN: Tutup sesi absensi
// POST /absensi.php?action=tutup_sesi
// ============================================================
if ($method === 'POST' && $action === 'tutup_sesi') {
    if (!in_array($user['role'], ['dosen', 'admin'])) {
        sendResponse('error', 'Akses ditolak', null, 403);
    }
    
    $body    = json_decode(file_get_contents('php://input'), true);
    $sesi_id = (int)($body['sesi_id'] ?? 0);
    
    $stmt = $conn->prepare("UPDATE sesi_absensi SET status = 'tutup' WHERE id = ?");
    $stmt->bind_param("i", $sesi_id);
    $stmt->execute();
    
    sendResponse('success', 'Sesi absensi ditutup');
}

// ============================================================
// MAHASISWA: Absen dengan kode manual
// POST /absensi.php?action=absen_kode
// ============================================================
if ($method === 'POST' && $action === 'absen_kode') {
    $body       = json_decode(file_get_contents('php://input'), true);
    $kode_absen = strtoupper(trim($body['kode_absen'] ?? ''));
    
    if (empty($kode_absen)) {
        sendResponse('error', 'Kode absen wajib diisi', null, 400);
    }
    
    $stmt = $conn->prepare("SELECT s.*, mk.nama_mk FROM sesi_absensi s
                            JOIN mata_kuliah mk ON s.mata_kuliah_id = mk.id
                            WHERE s.kode_absen = ? AND s.status = 'aktif'
                            AND s.tanggal = CURDATE()");
    $stmt->bind_param("s", $kode_absen);
    $stmt->execute();
    $sesi = $stmt->get_result()->fetch_assoc();
    
    if (!$sesi) {
        sendResponse('error', 'Kode absen tidak valid atau sesi sudah ditutup', null, 404);
    }
    
    _doAbsen($conn, $sesi, $user['id'], 'kode_manual');
}

// ============================================================
// MAHASISWA: Absen dengan QR Code
// POST /absensi.php?action=absen_qr
// ============================================================
if ($method === 'POST' && $action === 'absen_qr') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $qr_data  = trim($body['qr_data'] ?? '');
    
    $parts = explode('|', $qr_data);
    if (count($parts) !== 3 || $parts[0] !== 'ABSENSI') {
        sendResponse('error', 'QR Code tidak valid', null, 400);
    }
    
    $sesi_id  = (int)$parts[1];
    $qr_token = $parts[2];
    
    $stmt = $conn->prepare("SELECT s.*, mk.nama_mk FROM sesi_absensi s
                            JOIN mata_kuliah mk ON s.mata_kuliah_id = mk.id
                            WHERE s.id = ? AND s.qr_token = ? AND s.status = 'aktif'");
    $stmt->bind_param("is", $sesi_id, $qr_token);
    $stmt->execute();
    $sesi = $stmt->get_result()->fetch_assoc();
    
    if (!$sesi) {
        sendResponse('error', 'QR Code tidak valid atau sesi sudah ditutup', null, 404);
    }
    
    _doAbsen($conn, $sesi, $user['id'], 'qr_code');
}

// ============================================================
// Helper: Simpan record absensi
// ============================================================
function _doAbsen($conn, $sesi, $mahasiswa_id, $metode) {
    // Pastikan mahasiswa terdaftar di kelas yang sama dengan sesi ini
    $cekKelas = $conn->prepare(
        "SELECT id FROM enrollment
         WHERE mahasiswa_id = ? AND mata_kuliah_id = ? AND kelas = ?"
    );
    $cekKelas->bind_param("iis", $mahasiswa_id, $sesi['mata_kuliah_id'], $sesi['kelas']);
    $cekKelas->execute();
    if ($cekKelas->get_result()->num_rows === 0) {
        sendResponse('error', 'Anda tidak terdaftar di kelas ini', null, 403);
    }

    $cekStmt = $conn->prepare("SELECT id FROM absensi WHERE sesi_id = ? AND mahasiswa_id = ?");
    $cekStmt->bind_param("ii", $sesi['id'], $mahasiswa_id);
    $cekStmt->execute();
    if ($cekStmt->get_result()->num_rows > 0) {
        sendResponse('error', 'Anda sudah melakukan absensi untuk sesi ini', null, 409);
    }

    $waktuMulai      = strtotime($sesi['tanggal'] . ' ' . $sesi['jam_mulai']);
    $batasToleransi  = $waktuMulai + 15 * 60;
    $sekarang        = time();

    if ($sekarang < $waktuMulai) {
        sendResponse('error', 'Sesi absensi belum dimulai', null, 403);
    }

    if ($sekarang > $batasToleransi) {
        sendResponse('error', 'Waktu presensi telah habis. Toleransi keterlambatan hanya 15 menit dari jam mulai.', null, 403);
    }

    $status = 'hadir';

    $insStmt = $conn->prepare("INSERT INTO absensi (sesi_id, mahasiswa_id, metode, status) VALUES (?, ?, ?, ?)");
    $insStmt->bind_param("iiss", $sesi['id'], $mahasiswa_id, $metode, $status);
    
    if ($insStmt->execute()) {
        sendResponse('success', 'Absensi berhasil dicatat!', [
            'mata_kuliah' => $sesi['nama_mk'],
            'pertemuan'   => $sesi['pertemuan_ke'],
            'tanggal'     => $sesi['tanggal'],
            'status'      => $status,
            'metode'      => $metode,
        ]);
    } else {
        sendResponse('error', 'Gagal menyimpan absensi', null, 500);
    }
}

// ============================================================
// GET: Daftar sesi aktif untuk mahasiswa
// ============================================================
if ($method === 'GET' && $action === 'sesi_aktif') {
    // Filter sesi berdasarkan kelas enrollment mahasiswa
    $stmt = $conn->prepare("SELECT s.*, mk.nama_mk, mk.kode_mk, u.nama AS dosen_nama
                            FROM sesi_absensi s
                            JOIN mata_kuliah mk ON s.mata_kuliah_id = mk.id
                            JOIN users u ON mk.dosen_id = u.id
                            JOIN enrollment e ON e.mata_kuliah_id = mk.id
                                             AND e.mahasiswa_id = ?
                                             AND e.kelas = s.kelas
                            WHERE s.status = 'aktif' AND s.tanggal = CURDATE()
                            AND NOW() BETWEEN TIMESTAMP(s.tanggal, s.jam_mulai)
                            AND TIMESTAMP(s.tanggal, s.jam_mulai) + INTERVAL 15 MINUTE");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    sendResponse('success', 'Sesi aktif', $result);
}

// ============================================================
// GET: Detail sesi (untuk dosen/admin)
// GET /absensi.php?action=detail_sesi&sesi_id={id}
// ============================================================
if ($method === 'GET' && $action === 'detail_sesi') {
    $sesi_id = (int)($_GET['sesi_id'] ?? 0);
    $stmt = $conn->prepare("SELECT a.*, u.nama, u.nim_nidn
                            FROM absensi a
                            JOIN users u ON u.id = a.mahasiswa_id
                            WHERE a.sesi_id = ?
                            ORDER BY a.waktu_absen ASC");
    $stmt->bind_param("i", $sesi_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    sendResponse('success', 'Detail sesi', $result);
}

// ============================================================
// GET: Info perkuliahan mahasiswa
// ============================================================
if ($method === 'GET' && $action === 'info_perkuliahan') {
    $stmt = $conn->prepare("
        SELECT
            s.id            AS sesi_id,
            s.pertemuan_ke,
            s.tanggal,
            s.jam_mulai,
            s.jam_selesai,
            s.materi,
            s.status,
            mk.nama_mk,
            mk.kode_mk,
            a.status        AS status_absen,
            a.metode,
            a.waktu_absen
        FROM sesi_absensi s
        JOIN mata_kuliah mk  ON s.mata_kuliah_id = mk.id
        JOIN enrollment e    ON e.mata_kuliah_id  = mk.id
                            AND e.mahasiswa_id    = ?
                            AND e.kelas           = s.kelas
        LEFT JOIN absensi a  ON a.sesi_id         = s.id
                            AND a.mahasiswa_id    = ?
        WHERE s.tanggal >= CURDATE()
        ORDER BY s.tanggal ASC, s.jam_mulai ASC
    ");
    $stmt->bind_param("ii", $user['id'], $user['id']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    sendResponse('success', 'Info perkuliahan', $result);
}

// ============================================================
// GET: Presensi mahasiswa (seluruh semester)
// ============================================================
if ($method === 'GET' && $action === 'presensi_saya') {
    $stmt = $conn->prepare("
        SELECT
            mk.id, mk.kode_mk, mk.nama_mk, mk.sks, mk.semester,
            u.nama AS dosen_nama,
            CAST(COUNT(s.id) AS SIGNED)          AS total_pertemuan,
            CAST(SUM(CASE WHEN a.status = 'hadir'     THEN 1 ELSE 0 END) AS SIGNED) AS hadir,
            CAST(SUM(CASE WHEN a.status = 'terlambat' THEN 1 ELSE 0 END) AS SIGNED) AS terlambat,
            CAST(SUM(CASE WHEN s.id IS NOT NULL AND a.id IS NULL THEN 1 ELSE 0 END) AS SIGNED) AS alpha
        FROM mata_kuliah mk
        JOIN enrollment e      ON e.mata_kuliah_id = mk.id AND e.mahasiswa_id = ?
        JOIN users u           ON u.id = mk.dosen_id
        LEFT JOIN sesi_absensi s ON s.mata_kuliah_id = mk.id AND s.kelas = e.kelas
        LEFT JOIN absensi a    ON a.sesi_id = s.id AND a.mahasiswa_id = ?
        GROUP BY mk.id
        ORDER BY mk.kode_mk ASC
    ");
    $stmt->bind_param("ii", $user['id'], $user['id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $result = array_map(function($r) {
        $r['total_pertemuan'] = (int)$r['total_pertemuan'];
        $r['hadir']           = (int)$r['hadir'];
        $r['terlambat']       = (int)$r['terlambat'];
        $r['alpha']           = (int)$r['alpha'];
        return $r;
    }, $rows);
    sendResponse('success', 'Presensi saya', $result);
}

// ============================================================
// GET: Daftar sesi pertemuan berdasarkan mata kuliah (dosen)
// GET /absensi.php?action=sesi_by_matkul&mk_id={id}
// ============================================================
if ($method === 'GET' && $action === 'sesi_by_matkul') {
    if (!in_array($user['role'], ['dosen', 'admin'])) {
        sendResponse('error', 'Akses ditolak', null, 403);
    }

    $mk_id = (int)($_GET['mk_id'] ?? 0);
    if ($mk_id === 0) {
        sendResponse('error', 'mk_id wajib diisi', null, 400);
    }

    $chk = $conn->prepare("SELECT id FROM mata_kuliah WHERE id = ? AND dosen_id = ?");
    $chk->bind_param("ii", $mk_id, $user['id']);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        sendResponse('error', 'Mata kuliah tidak ditemukan', null, 404);
    }

    $stmt = $conn->prepare("
        SELECT
            s.id          AS id_sesi,
            s.pertemuan_ke,
            s.kelas,
            s.tanggal,
            s.jam_mulai,
            s.jam_selesai,
            s.materi,
            s.status,
            s.kode_absen,
            (SELECT COUNT(*) FROM absensi a WHERE a.sesi_id = s.id) AS total_hadir,
            (SELECT COUNT(*) FROM enrollment e
             WHERE e.mata_kuliah_id = s.mata_kuliah_id AND e.kelas = s.kelas) AS total_mahasiswa
        FROM sesi_absensi s
        WHERE s.mata_kuliah_id = ?
        ORDER BY s.tanggal DESC, s.jam_mulai DESC
    ");
    $stmt->bind_param("i", $mk_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $result = array_map(function($r) {
        $r['id_sesi']          = (int)$r['id_sesi'];
        $r['pertemuan_ke']     = (int)$r['pertemuan_ke'];
        $r['total_hadir']      = (int)$r['total_hadir'];
        $r['total_mahasiswa']  = (int)$r['total_mahasiswa'];
        return $r;
    }, $rows);

    sendResponse('success', 'Daftar sesi', $result);
}

// ============================================================
// GET: Rekap kehadiran per sesi — semua mahasiswa (dosen)
// GET /absensi.php?action=rekap_kehadiran&sesi_id={id}
// ============================================================
if ($method === 'GET' && $action === 'rekap_kehadiran') {
    if (!in_array($user['role'], ['dosen', 'admin'])) {
        sendResponse('error', 'Akses ditolak', null, 403);
    }

    $sesi_id = (int)($_GET['sesi_id'] ?? 0);
    if ($sesi_id === 0) {
        sendResponse('error', 'sesi_id wajib diisi', null, 400);
    }

    $chk = $conn->prepare("
        SELECT s.id FROM sesi_absensi s
        JOIN mata_kuliah mk ON mk.id = s.mata_kuliah_id
        WHERE s.id = ? AND mk.dosen_id = ?
    ");
    $chk->bind_param("ii", $sesi_id, $user['id']);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        sendResponse('error', 'Sesi tidak ditemukan', null, 404);
    }

    // Mahasiswa yang SUDAH absen
    $stmtHadir = $conn->prepare("
        SELECT
            u.nama,
            u.nim_nidn,
            e.kelas,
            a.status        AS status_absen,
            a.metode,
            TIME(a.waktu_absen) AS waktu_absen
        FROM absensi a
        JOIN users u      ON u.id = a.mahasiswa_id
        JOIN sesi_absensi s ON s.id = a.sesi_id
        JOIN enrollment e ON e.mahasiswa_id = a.mahasiswa_id
                         AND e.mata_kuliah_id = s.mata_kuliah_id
        WHERE a.sesi_id = ?
        ORDER BY e.kelas ASC, a.waktu_absen ASC
    ");
    $stmtHadir->bind_param("i", $sesi_id);
    $stmtHadir->execute();
    $hadir = $stmtHadir->get_result()->fetch_all(MYSQLI_ASSOC);

    // Mahasiswa yang BELUM absen (hanya dari kelas yang dibuka di sesi ini)
    $stmtBelum = $conn->prepare("
        SELECT
            u.nama,
            u.nim_nidn,
            e.kelas
        FROM enrollment e
        JOIN users u        ON u.id = e.mahasiswa_id
        JOIN sesi_absensi s ON s.id = ?
        WHERE e.mata_kuliah_id = s.mata_kuliah_id
          AND e.kelas = s.kelas
          AND e.mahasiswa_id NOT IN (
              SELECT mahasiswa_id FROM absensi WHERE sesi_id = ?
          )
        ORDER BY u.nama ASC
    ");
    $stmtBelum->bind_param("ii", $sesi_id, $sesi_id);
    $stmtBelum->execute();
    $belum_absen = $stmtBelum->get_result()->fetch_all(MYSQLI_ASSOC);

    sendResponse('success', 'Rekap kehadiran', [
        'hadir'       => $hadir,
        'belum_absen' => $belum_absen,
    ]);
}

// ============================================================
// NEW: GET Rekap kehadiran dikelompokkan per kelas
// GET /absensi.php?action=rekap_per_kelas&sesi_id={id}
// ============================================================
if ($method === 'GET' && $action === 'rekap_per_kelas') {
    if (!in_array($user['role'], ['dosen', 'admin'])) {
        sendResponse('error', 'Akses ditolak', null, 403);
    }

    $sesi_id = (int)($_GET['sesi_id'] ?? 0);
    if ($sesi_id === 0) {
        sendResponse('error', 'sesi_id wajib diisi', null, 400);
    }

    // Verifikasi sesi milik dosen
    $chk = $conn->prepare("
        SELECT s.id, mk.nama_mk, s.pertemuan_ke, s.tanggal
        FROM sesi_absensi s
        JOIN mata_kuliah mk ON mk.id = s.mata_kuliah_id
        WHERE s.id = ? AND mk.dosen_id = ?
    ");
    $chk->bind_param("ii", $sesi_id, $user['id']);
    $chk->execute();
    $sesiInfo = $chk->get_result()->fetch_assoc();
    if (!$sesiInfo) {
        sendResponse('error', 'Sesi tidak ditemukan', null, 404);
    }

    // Ambil semua mahasiswa + status absensi — hanya kelas yang dibuka di sesi ini
    $stmt = $conn->prepare("
        SELECT
            e.kelas,
            u.id           AS mahasiswa_id,
            u.nama,
            u.nim_nidn,
            a.status       AS status_absen,
            a.metode,
            TIME(a.waktu_absen) AS waktu_absen
        FROM enrollment e
        JOIN users u        ON u.id = e.mahasiswa_id
        JOIN sesi_absensi s ON s.id = ?
        LEFT JOIN absensi a ON a.sesi_id = ? AND a.mahasiswa_id = u.id
        WHERE e.mata_kuliah_id = s.mata_kuliah_id
          AND e.kelas = s.kelas
        ORDER BY e.kelas ASC, u.nama ASC
    ");
    $stmt->bind_param("ii", $sesi_id, $sesi_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Kelompokkan per kelas
    $grouped = [];
    foreach ($rows as $row) {
        $kelasNama = $row['kelas'] ?? 'Tanpa Kelas';
        if (!isset($grouped[$kelasNama])) {
            $grouped[$kelasNama] = [
                'kelas'           => $kelasNama,
                'total'           => 0,
                'hadir'           => 0,
                'belum_absen'     => 0,
                'mahasiswa'       => [],
            ];
        }
        $sudahHadir = $row['status_absen'] !== null;
        $grouped[$kelasNama]['total']++;
        if ($sudahHadir) {
            $grouped[$kelasNama]['hadir']++;
        } else {
            $grouped[$kelasNama]['belum_absen']++;
        }
        $grouped[$kelasNama]['mahasiswa'][] = [
            'nama'        => $row['nama'],
            'nim_nidn'    => $row['nim_nidn'],
            'status_absen'=> $row['status_absen'] ?? 'belum',
            'metode'      => $row['metode'],
            'waktu_absen' => $row['waktu_absen'],
        ];
    }

    sendResponse('success', 'Rekap per kelas', [
        'sesi'   => $sesiInfo,
        'kelas'  => array_values($grouped),
    ]);
}

// ============================================================
// NEW: GET Daftar kelas
// GET /absensi.php?action=list_kelas
// ============================================================
if ($method === 'GET' && $action === 'list_kelas') {
    // Ambil kelas berdasarkan parameter mk_id (opsional)
    $mk_id = (int)($_GET['mk_id'] ?? 0);

    if ($mk_id > 0) {
        $stmt = $conn->prepare("
            SELECT e.kelas, COUNT(DISTINCT e.mahasiswa_id) AS total_mahasiswa
            FROM enrollment e
            WHERE e.mata_kuliah_id = ?
            GROUP BY e.kelas
            ORDER BY e.kelas ASC
        ");
        $stmt->bind_param("i", $mk_id);
    } else {
        $stmt = $conn->prepare("
            SELECT e.kelas, COUNT(DISTINCT e.mahasiswa_id) AS total_mahasiswa
            FROM enrollment e
            GROUP BY e.kelas
            ORDER BY e.kelas ASC
        ");
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    sendResponse('success', 'Daftar kelas', $result);
}

// ============================================================
// NEW: GET Rekap kehadiran per matkul untuk semua kelas (summary)
// GET /absensi.php?action=rekap_matkul_kelas&mk_id={id}
// ============================================================
if ($method === 'GET' && $action === 'rekap_matkul_kelas') {
    if (!in_array($user['role'], ['dosen', 'admin'])) {
        sendResponse('error', 'Akses ditolak', null, 403);
    }

    $mk_id = (int)($_GET['mk_id'] ?? 0);
    if ($mk_id === 0) {
        sendResponse('error', 'mk_id wajib diisi', null, 400);
    }

    // Summary kehadiran per kelas per sesi untuk satu matkul
    $stmt = $conn->prepare("
        SELECT
            s.kelas,
            s.pertemuan_ke,
            s.tanggal,
            COUNT(DISTINCT e.mahasiswa_id)               AS total_mahasiswa,
            COUNT(DISTINCT a.mahasiswa_id)               AS total_hadir
        FROM sesi_absensi s
        JOIN enrollment e ON e.mata_kuliah_id = s.mata_kuliah_id
                         AND e.kelas = s.kelas
        LEFT JOIN absensi a ON a.sesi_id = s.id AND a.mahasiswa_id = e.mahasiswa_id
        WHERE s.mata_kuliah_id = ?
        GROUP BY s.id
        ORDER BY s.tanggal ASC, s.kelas ASC
    ");
    $stmt->bind_param("i", $mk_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    sendResponse('success', 'Rekap matkul per kelas', $rows);
}

sendResponse('error', 'Endpoint tidak ditemukan', null, 404);
