-- Closet Inventory schema v1
-- Idempotent: every CREATE uses IF NOT EXISTS; version row is INSERT IGNORE.

CREATE TABLE IF NOT EXISTS tcs_closet_schools (
  id           VARCHAR(16) NOT NULL PRIMARY KEY,
  name         VARCHAR(128) NOT NULL,
  type         VARCHAR(16) NOT NULL,
  zbx_group    VARCHAR(128) NULL,
  color_hue    SMALLINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tcs_closet_closets (
  uid          INT AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(64) NOT NULL,
  type         VARCHAR(8)  NOT NULL,
  school_id    VARCHAR(16) NOT NULL,
  building     VARCHAR(64) NULL,
  floor        INT NULL,
  room         VARCHAR(64) NULL,
  flagged      TINYINT(1) NOT NULL DEFAULT 0,
  flag_reason  VARCHAR(255) NULL,
  flag_tech    VARCHAR(64) NULL,
  flag_date    DATE NULL,
  ports_total  INT NOT NULL DEFAULT 0,
  ports_used   INT NOT NULL DEFAULT 0,
  updated_at   DATETIME NULL,
  UNIQUE KEY uk_closet_code (code),
  KEY idx_closet_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tcs_closet_switches (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid        INT NOT NULL,
  name              VARCHAR(64) NULL,
  vendor            VARCHAR(32) NULL,
  model             VARCHAR(64) NULL,
  ports             INT NULL,
  used              INT NULL DEFAULT 0,
  poe               TINYINT(1) NOT NULL DEFAULT 0,
  uplinks           INT NULL,
  uplink_speed      VARCHAR(16) NULL,
  mgmt_ip           VARCHAR(64) NULL,
  serial            VARCHAR(64) NULL,
  stack_size        INT NOT NULL DEFAULT 1,
  zabbix_hostid     VARCHAR(32) NULL,
  xiq_device_id     BIGINT NULL,
  rconfig_device_id INT NULL,
  KEY idx_switch_closet (closet_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tcs_closet_power_units (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid  INT NOT NULL,
  kind        VARCHAR(8) NOT NULL,
  model       VARCHAR(96) NULL,
  va          INT NULL,
  load_pct    INT NULL,
  battery_pct INT NULL,
  runtime_min INT NULL,
  outlets     INT NULL,
  load_amps   DECIMAL(5,1) NULL,
  KEY idx_pwr_closet (closet_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tcs_closet_circuits (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid  INT NOT NULL,
  label       VARCHAR(64) NULL,
  KEY idx_circ_closet (closet_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tcs_closet_maintenance (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid  INT NOT NULL,
  date        DATE NULL,
  type        VARCHAR(96) NULL,
  tone        VARCHAR(16) NULL,
  tech        VARCHAR(64) NULL,
  notes       TEXT NULL,
  KEY idx_maint_closet (closet_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tcs_closet_photos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  closet_uid  INT NOT NULL,
  label       VARCHAR(64) NULL,
  path        VARCHAR(255) NOT NULL,
  KEY idx_photo_closet (closet_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tcs_closet_audit (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  ts      DATETIME NOT NULL,
  userid  VARCHAR(32) NOT NULL,
  action  VARCHAR(64) NOT NULL,
  target  VARCHAR(128) NULL,
  result  VARCHAR(255) NULL,
  KEY idx_audit_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO tcs_closet_schema_version (version) VALUES (1)
