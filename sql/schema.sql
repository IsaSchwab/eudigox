-- =====================================================================
-- SGX - Sistema de Triagem da Síndrome do X Frágil
-- Schema MySQL 8.0+
-- =====================================================================
-- Convenções:
--   * InnoDB + utf8mb4 para suporte completo a Unicode (acentos, emoji)
--   * Chaves primárias BIGINT UNSIGNED AUTO_INCREMENT
--   * created_at / updated_at em TODAS as tabelas (auditoria)
--   * Soft delete via deleted_at (nunca apagar dado de paciente)
--   * Senhas SEMPRE como hash (password_hash do PHP, bcrypt)
-- =====================================================================

DROP DATABASE IF EXISTS sgx_db;
CREATE DATABASE sgx_db
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE sgx_db;

-- ---------------------------------------------------------------------
-- USERS — tabela única para todos os perfis (paciente, enfermeiro, médico, admin)
-- O perfil define o que cada um pode acessar (RBAC).
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(180) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  full_name       VARCHAR(180) NOT NULL,
  role            ENUM('patient', 'nurse', 'doctor', 'admin') NOT NULL,
  professional_id VARCHAR(40)  NULL COMMENT 'CRM, COREN ou similar (apenas para nurse/doctor)',
  phone           VARCHAR(20)  NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at   DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME     NULL,
  INDEX idx_users_role (role),
  INDEX idx_users_active (is_active, deleted_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- PATIENTS — dados do paciente avaliado
-- Vinculado ao user (caso seja auto-cadastro) OU criado por profissional
-- (nesse caso user_id = NULL e o paciente não tem login).
-- ---------------------------------------------------------------------
CREATE TABLE patients (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id               BIGINT UNSIGNED NULL UNIQUE COMMENT 'Vínculo opcional com users (auto-cadastro)',
  full_name             VARCHAR(180) NOT NULL,
  birth_date            DATE NOT NULL,
  biological_sex        ENUM('M', 'F') NOT NULL COMMENT 'Essencial para o cálculo do score',
  cpf                   VARCHAR(14)  NULL UNIQUE,
  guardian_name         VARCHAR(180) NULL,
  guardian_relationship VARCHAR(40)  NULL,
  guardian_phone        VARCHAR(20)  NULL,
  guardian_email        VARCHAR(180) NULL,
  created_by_user_id    BIGINT UNSIGNED NULL COMMENT 'Profissional que cadastrou (NULL se auto-cadastro)',
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            DATETIME NULL,
  CONSTRAINT fk_patients_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_patients_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_patients_birth (birth_date),
  INDEX idx_patients_sex   (biological_sex)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- INDICATORS — catálogo dos 12 indicadores fenotípicos
-- Tabela de domínio. Permite ajustar pesos sem mexer no código.
-- ---------------------------------------------------------------------
CREATE TABLE indicators (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(40)  NOT NULL UNIQUE COMMENT 'Identificador estável (ex: SPEECH_DELAY)',
  display_name      VARCHAR(120) NOT NULL COMMENT 'Nome técnico (ex: Atraso na fala)',
  lay_label         VARCHAR(180) NOT NULL COMMENT 'Linguagem leiga (ex: Demora para começar a falar)',
  clinical_tooltip  TEXT         NOT NULL COMMENT 'Explicação clínica detalhada',
  category          ENUM('development', 'behavioral', 'physical') NOT NULL,
  weight_male       DECIMAL(5,4) NOT NULL DEFAULT 0.0000 COMMENT 'Peso estatístico para sexo masculino',
  weight_female     DECIMAL(5,4) NOT NULL DEFAULT 0.0000 COMMENT 'Peso estatístico para sexo feminino',
  applies_to        ENUM('both', 'M', 'F') NOT NULL DEFAULT 'both',
  display_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_indicators_category (category, display_order)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SCREENINGS — cada avaliação (triagem) feita por/para um paciente
-- Um paciente pode ter MÚLTIPLAS triagens ao longo do tempo (RF005).
-- ---------------------------------------------------------------------
CREATE TABLE screenings (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id            BIGINT UNSIGNED NOT NULL,
  performed_by_user_id  BIGINT UNSIGNED NULL COMMENT 'Profissional responsável (NULL se auto-triagem)',
  score                 DECIMAL(5,4) NULL COMMENT 'Calculado automaticamente após responder todos indicadores',
  threshold_applied     DECIMAL(5,4) NULL COMMENT 'Limiar usado (0.56 M / 0.55 F)',
  priority              ENUM('high', 'medium', 'low') NULL,
  recommendation        ENUM('refer_molecular', 'monitor', 'no_action') NULL,
  clinical_notes        TEXT NULL COMMENT 'Observações livres do profissional (RF008)',
  include_notes_in_report TINYINT(1) NOT NULL DEFAULT 0,
  status                ENUM('draft', 'submitted', 'reviewed', 'closed') NOT NULL DEFAULT 'draft',
  submitted_at          DATETIME NULL,
  reviewed_at           DATETIME NULL,
  reviewed_by_user_id   BIGINT UNSIGNED NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            DATETIME NULL,
  CONSTRAINT fk_screenings_patient
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_screenings_performed_by
    FOREIGN KEY (performed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_screenings_reviewed_by
    FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_screenings_patient   (patient_id),
  INDEX idx_screenings_status    (status),
  INDEX idx_screenings_priority  (priority),
  INDEX idx_screenings_submitted (submitted_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SCREENING_ANSWERS — resposta de um indicador dentro de uma triagem
-- (relação N:N entre screenings e indicators com payload)
-- ---------------------------------------------------------------------
CREATE TABLE screening_answers (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  screening_id  BIGINT UNSIGNED NOT NULL,
  indicator_id  BIGINT UNSIGNED NOT NULL,
  answer        ENUM('yes', 'no', 'unknown') NOT NULL,
  observation   TEXT NULL COMMENT 'Observação opcional por indicador',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_answers_screening
    FOREIGN KEY (screening_id) REFERENCES screenings(id) ON DELETE CASCADE,
  CONSTRAINT fk_answers_indicator
    FOREIGN KEY (indicator_id) REFERENCES indicators(id) ON DELETE RESTRICT,
  UNIQUE KEY uk_screening_indicator (screening_id, indicator_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- MOLECULAR_EXAMS — registro de exames moleculares (PCR / Southern Blotting)
-- Apenas médico registra. Fecha o ciclo diagnóstico.
-- ---------------------------------------------------------------------
CREATE TABLE molecular_exams (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id          BIGINT UNSIGNED NOT NULL,
  screening_id        BIGINT UNSIGNED NULL COMMENT 'Triagem que motivou o exame',
  exam_type           ENUM('pcr', 'southern_blotting') NOT NULL,
  exam_date           DATE NOT NULL,
  result              ENUM('positive', 'negative', 'inconclusive') NOT NULL,
  laboratory          VARCHAR(180) NULL,
  report_file_path    VARCHAR(255) NULL COMMENT 'Caminho do laudo (PDF) anexado',
  notes               TEXT NULL,
  registered_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          DATETIME NULL,
  CONSTRAINT fk_exams_patient
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_exams_screening
    FOREIGN KEY (screening_id) REFERENCES screenings(id) ON DELETE SET NULL,
  CONSTRAINT fk_exams_registered_by
    FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_exams_patient (patient_id),
  INDEX idx_exams_result  (result)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- APPOINTMENTS — consultas agendadas
-- ---------------------------------------------------------------------
CREATE TABLE appointments (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id        BIGINT UNSIGNED NOT NULL,
  doctor_user_id    BIGINT UNSIGNED NOT NULL,
  scheduled_at      DATETIME NOT NULL,
  location          VARCHAR(180) NULL,
  status            ENUM('scheduled', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'scheduled',
  notes             TEXT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_appointments_patient
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_appointments_doctor
    FOREIGN KEY (doctor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_appointments_scheduled (scheduled_at),
  INDEX idx_appointments_patient   (patient_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- AUTH_TOKENS — tokens de sessão para a API REST (autenticação stateless)
-- Alternativa simples ao JWT, suficiente para o escopo do TCC.
-- ---------------------------------------------------------------------
CREATE TABLE auth_tokens (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  token       CHAR(64)  NOT NULL UNIQUE COMMENT 'SHA-256 hex',
  expires_at  DATETIME  NOT NULL,
  ip_address  VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tokens_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_tokens_expires (expires_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- AUDIT_LOGS — log de operações sensíveis (RNF005)
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NULL,
  action       VARCHAR(80)  NOT NULL COMMENT 'Ex: PATIENT_CREATED, EXAM_REGISTERED',
  entity_type  VARCHAR(40)  NOT NULL COMMENT 'Ex: patient, screening, exam',
  entity_id    BIGINT UNSIGNED NULL,
  details      JSON NULL COMMENT 'Snapshot dos dados relevantes',
  ip_address   VARCHAR(45) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_user    (user_id),
  INDEX idx_audit_entity  (entity_type, entity_id),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

-- =====================================================================
-- SEED DE INDICADORES (pesos clínicos)
-- =====================================================================
-- Os pesos dos indicadores são definidos junto ao Instituto Buko
-- Kaesemodel e NÃO são versionados neste repositório.
-- Após importar este schema, rode o seed privado:
--   sql/seeds-local/seed-indicators.sql
-- (solicite o arquivo à equipe do projeto)
-- =====================================================================
