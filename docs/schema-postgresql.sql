CREATE TABLE IF NOT EXISTS va_user (
  id BIGSERIAL PRIMARY KEY,
  username VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'leitor',
  active SMALLINT NOT NULL DEFAULT 1,
  failed_logins INTEGER NOT NULL DEFAULT 0,
  locked_until TIMESTAMP(0) NULL,
  last_login_at TIMESTAMP(0) NULL,
  created_at TIMESTAMP(0) NOT NULL,
  updated_at TIMESTAMP(0) NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_va_user_username ON va_user (username);

CREATE TABLE IF NOT EXISTS va_api_token (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL,
  name VARCHAR(80) NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  token_prefix VARCHAR(12) NOT NULL,
  last_used_at TIMESTAMP(0) NULL,
  expires_at TIMESTAMP(0) NULL,
  created_at TIMESTAMP(0) NOT NULL,
  FOREIGN KEY (user_id) REFERENCES va_user(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_va_api_token_hash ON va_api_token (token_hash);

CREATE INDEX IF NOT EXISTS ix_va_api_token_user ON va_api_token (user_id);

CREATE TABLE IF NOT EXISTS va_session (
  id VARCHAR(128) PRIMARY KEY,
  user_id BIGINT NULL,
  payload TEXT NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  last_activity BIGINT NOT NULL,
  FOREIGN KEY (user_id) REFERENCES va_user(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS ix_va_session_activity ON va_session (last_activity);

CREATE TABLE IF NOT EXISTS va_project (
  id BIGSERIAL PRIMARY KEY,
  slug VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  provider VARCHAR(10) NOT NULL DEFAULT 'github',
  repo_url VARCHAR(500) NOT NULL,
  default_branch VARCHAR(120) NOT NULL DEFAULT 'main',
  webhook_secret_enc TEXT NOT NULL,
  access_token_enc TEXT NULL,
  branch_filter VARCHAR(500) NULL,
  events VARCHAR(60) NOT NULL DEFAULT 'push,pull_request,tag',
  pipeline_yaml TEXT NULL,
  timeout_minutes INTEGER NOT NULL DEFAULT 30,
  cpus VARCHAR(10) NOT NULL DEFAULT 1.0,
  memory VARCHAR(10) NOT NULL DEFAULT '1g',
  network VARCHAR(20) NOT NULL DEFAULT 'bridge',
  auto_cancel SMALLINT NOT NULL DEFAULT 1,
  allow_deploy SMALLINT NOT NULL DEFAULT 1,
  report_status SMALLINT NOT NULL DEFAULT 1,
  notify_url VARCHAR(500) NULL,
  notify_on VARCHAR(10) NOT NULL DEFAULT 'falhas',
  active SMALLINT NOT NULL DEFAULT 1,
  created_by BIGINT NULL,
  created_at TIMESTAMP(0) NOT NULL,
  updated_at TIMESTAMP(0) NOT NULL,
  FOREIGN KEY (created_by) REFERENCES va_user(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_va_project_slug ON va_project (slug);

CREATE TABLE IF NOT EXISTS va_secret (
  id BIGSERIAL PRIMARY KEY,
  project_id BIGINT NOT NULL,
  name VARCHAR(80) NOT NULL,
  value_enc TEXT NOT NULL,
  deploy_only SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP(0) NOT NULL,
  updated_at TIMESTAMP(0) NOT NULL,
  FOREIGN KEY (project_id) REFERENCES va_project(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_va_secret_name ON va_secret (project_id, name);

CREATE TABLE IF NOT EXISTS va_webhook_delivery (
  id BIGSERIAL PRIMARY KEY,
  project_id BIGINT NULL,
  provider VARCHAR(10) NOT NULL,
  event VARCHAR(60) NOT NULL,
  delivery_uid VARCHAR(100) NOT NULL,
  signature_ok SMALLINT NOT NULL DEFAULT 0,
  status_code INTEGER NOT NULL,
  outcome VARCHAR(255) NOT NULL,
  headers TEXT NOT NULL,
  payload TEXT NOT NULL,
  payload_size INTEGER NOT NULL DEFAULT 0,
  ip VARCHAR(45) NULL,
  received_at TIMESTAMP(0) NOT NULL,
  FOREIGN KEY (project_id) REFERENCES va_project(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS ix_va_delivery_project ON va_webhook_delivery (project_id, received_at);

CREATE INDEX IF NOT EXISTS ix_va_delivery_uid ON va_webhook_delivery (delivery_uid);

CREATE INDEX IF NOT EXISTS ix_va_delivery_received ON va_webhook_delivery (received_at);

CREATE TABLE IF NOT EXISTS va_build (
  id BIGSERIAL PRIMARY KEY,
  project_id BIGINT NOT NULL,
  number INTEGER NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'queued',
  trigger_kind VARCHAR(16) NOT NULL,
  event VARCHAR(40) NOT NULL,
  git_ref VARCHAR(255) NOT NULL,
  branch VARCHAR(255) NULL,
  tag VARCHAR(255) NULL,
  commit_sha VARCHAR(64) NULL,
  commit_message VARCHAR(500) NULL,
  commit_author VARCHAR(120) NULL,
  commit_url VARCHAR(500) NULL,
  pr_number INTEGER NULL,
  clone_url VARCHAR(500) NULL,
  is_fork SMALLINT NOT NULL DEFAULT 0,
  is_deploy SMALLINT NOT NULL DEFAULT 0,
  delivery_id BIGINT NULL,
  requested_by BIGINT NULL,
  retry_of BIGINT NULL,
  worker_id VARCHAR(64) NULL,
  pipeline_file VARCHAR(60) NULL,
  pipeline_json TEXT NULL,
  queued_at TIMESTAMP(0) NOT NULL,
  started_at TIMESTAMP(0) NULL,
  finished_at TIMESTAMP(0) NULL,
  duration_ms BIGINT NULL,
  exit_code INTEGER NULL,
  error_message VARCHAR(500) NULL,
  cancel_requested SMALLINT NOT NULL DEFAULT 0,
  log_bytes BIGINT NOT NULL DEFAULT 0,
  FOREIGN KEY (project_id) REFERENCES va_project(id) ON DELETE CASCADE,
  FOREIGN KEY (delivery_id) REFERENCES va_webhook_delivery(id) ON DELETE SET NULL,
  FOREIGN KEY (requested_by) REFERENCES va_user(id) ON DELETE SET NULL,
  FOREIGN KEY (retry_of) REFERENCES va_build(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_va_build_number ON va_build (project_id, number);

CREATE INDEX IF NOT EXISTS ix_va_build_status ON va_build (status, id);

CREATE INDEX IF NOT EXISTS ix_va_build_branch ON va_build (project_id, branch, status);

CREATE INDEX IF NOT EXISTS ix_va_build_queued ON va_build (queued_at);

CREATE TABLE IF NOT EXISTS va_build_step (
  id BIGSERIAL PRIMARY KEY,
  build_id BIGINT NOT NULL,
  position INTEGER NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'step',
  image VARCHAR(255) NULL,
  command TEXT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  exit_code INTEGER NULL,
  started_at TIMESTAMP(0) NULL,
  finished_at TIMESTAMP(0) NULL,
  duration_ms BIGINT NULL,
  note VARCHAR(255) NULL,
  FOREIGN KEY (build_id) REFERENCES va_build(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_va_step_position ON va_build_step (build_id, position);

CREATE TABLE IF NOT EXISTS va_build_log (
  id BIGSERIAL PRIMARY KEY,
  build_id BIGINT NOT NULL,
  step_id BIGINT NULL,
  stream VARCHAR(3) NOT NULL DEFAULT 'out',
  content TEXT NOT NULL,
  created_at TIMESTAMP(0) NOT NULL,
  FOREIGN KEY (build_id) REFERENCES va_build(id) ON DELETE CASCADE,
  FOREIGN KEY (step_id) REFERENCES va_build_step(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS ix_va_log_build ON va_build_log (build_id, id);

CREATE TABLE IF NOT EXISTS va_worker (
  id VARCHAR(64) PRIMARY KEY,
  hostname VARCHAR(120) NOT NULL,
  pid INTEGER NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'idle',
  current_build_id BIGINT NULL,
  builds_done INTEGER NOT NULL DEFAULT 0,
  started_at TIMESTAMP(0) NOT NULL,
  heartbeat_at TIMESTAMP(0) NOT NULL,
  FOREIGN KEY (current_build_id) REFERENCES va_build(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS ix_va_worker_heartbeat ON va_worker (heartbeat_at);

CREATE TABLE IF NOT EXISTS va_audit (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NULL,
  action VARCHAR(60) NOT NULL,
  target_type VARCHAR(30) NULL,
  target_id VARCHAR(64) NULL,
  details TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP(0) NOT NULL,
  FOREIGN KEY (user_id) REFERENCES va_user(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS ix_va_audit_created ON va_audit (created_at);

CREATE TABLE IF NOT EXISTS va_rate_limit (
  bucket VARCHAR(120) PRIMARY KEY,
  hits INTEGER NOT NULL DEFAULT 0,
  window_start BIGINT NOT NULL
);
