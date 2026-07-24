-- ============================================
-- DATABASE SISTEM ABSENSI MODERN
-- Import file ini di phpMyAdmin XAMPP
-- ============================================

CREATE DATABASE IF NOT EXISTS db_absensi;
USE db_absensi;

-- Tabel Admin/Dosen
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'dosen', 'mahasiswa') NOT NULL DEFAULT 'mahasiswa',
    nim_nidn VARCHAR(20) UNIQUE,
    foto VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabel Mata Kuliah
CREATE TABLE mata_kuliah (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode_mk VARCHAR(20) UNIQUE NOT NULL,
    nama_mk VARCHAR(100) NOT NULL,
    sks INT NOT NULL DEFAULT 2,
    semester INT NOT NULL,
    dosen_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dosen_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Tabel Enrollment (Mahasiswa - Mata Kuliah)
CREATE TABLE enrollment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mahasiswa_id INT NOT NULL,
    mata_kuliah_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_enrollment (mahasiswa_id, mata_kuliah_id),
    FOREIGN KEY (mahasiswa_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (mata_kuliah_id) REFERENCES mata_kuliah(id) ON DELETE CASCADE
);

-- Tabel Sesi Absensi (dibuat oleh dosen)
CREATE TABLE sesi_absensi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mata_kuliah_id INT NOT NULL,
    tanggal DATE NOT NULL,
    jam_mulai TIME NOT NULL,
    jam_selesai TIME NOT NULL,
    kode_absen VARCHAR(10) NOT NULL,
    qr_token VARCHAR(255) UNIQUE NOT NULL,
    pertemuan_ke INT NOT NULL DEFAULT 1,
    materi VARCHAR(255) DEFAULT NULL,
    status ENUM('aktif', 'tutup') DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mata_kuliah_id) REFERENCES mata_kuliah(id) ON DELETE CASCADE
);

-- Tabel Record Absensi Mahasiswa
CREATE TABLE absensi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sesi_id INT NOT NULL,
    mahasiswa_id INT NOT NULL,
    waktu_absen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    metode ENUM('qr_code', 'kode_manual') NOT NULL,
    status ENUM('hadir', 'terlambat', 'alpha') DEFAULT 'hadir',
    UNIQUE KEY unique_absen (sesi_id, mahasiswa_id),
    FOREIGN KEY (sesi_id) REFERENCES sesi_absensi(id) ON DELETE CASCADE,
    FOREIGN KEY (mahasiswa_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================
-- DATA AWAL / SEED DATA
-- ============================================

-- Password default: "password123" (di-hash dengan bcrypt)
INSERT INTO users (nama, email, password, role, nim_nidn) VALUES
('Admin Sistem', 'admin@kampus.ac.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'ADMIN001'),
('Dr. Budi Santoso', 'budi@kampus.ac.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'dosen', 'NIDN001'),
('Ahmad Mahasiswa', 'ahmad@mahasiswa.ac.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'mahasiswa', '2021001'),
('Siti Rahayu', 'siti@mahasiswa.ac.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'mahasiswa', '2021002');

INSERT INTO mata_kuliah (kode_mk, nama_mk, sks, semester, dosen_id) VALUES
('IF101', 'Pemrograman Mobile', 3, 5, 2),
('IF102', 'Basis Data', 3, 3, 2),
('IF103', 'Algoritma & Struktur Data', 3, 3, 2);

INSERT INTO enrollment (mahasiswa_id, mata_kuliah_id) VALUES
(3, 1), (3, 2), (3, 3),
(4, 1), (4, 2);
