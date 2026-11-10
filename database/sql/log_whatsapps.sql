-- Jalankan di database aplikasi (DB_CONNECTION / mysql).
-- Log antrian kirim WhatsApp Data Tagihan.

CREATE TABLE IF NOT EXISTS `log_whatsapps` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status` INT NOT NULL DEFAULT 0 COMMENT '0=antrian, 200=terkirim, -1=gagal',
  `log_id` INT NOT NULL DEFAULT 0,
  `user_id` INT NULL,
  `custid` VARCHAR(50) NULL,
  `nama` VARCHAR(255) NULL,
  `no_wa` VARCHAR(30) NULL,
  `pesan` LONGTEXT NULL,
  `response` LONGTEXT NULL,
  `client` VARCHAR(20) NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_whatsapps_custid` (`custid`),
  KEY `idx_log_whatsapps_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
