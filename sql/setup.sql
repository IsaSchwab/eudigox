-- =====================================================================
-- Eu Digo X — SETUP COMPLETO DO BANCO (importe este arquivo e pronto)
-- Gera o banco sgx_db do zero: estrutura + migracoes + indicadores + contas de teste.
-- Uso (MAMP): phpMyAdmin -> Importar -> selecione este arquivo -> Executar.
-- =====================================================================

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

-- ============ migration-profissionais-e-role ============
USE sgx_db;
-- =====================================================================
-- Migration: papel 'receptionist' + cadastro de profissionais
-- Idempotente: pode rodar mais de uma vez sem duplicar.
--
-- NOTA: os dados reais dos profissionais (nome, e-mail, senha) NÃO são
-- versionados neste repositório por segurança. O exemplo abaixo usa
-- placeholders — a equipe do projeto mantém o seed real em
-- sql/seeds-local/ (fora do git).
-- =====================================================================

-- 1) Amplia o ENUM de papéis
ALTER TABLE users
  MODIFY COLUMN role
  ENUM('patient', 'nurse', 'doctor', 'admin', 'receptionist') NOT NULL;

-- 2) Exemplo de cadastro de profissional (PLACEHOLDER)
--    Gere o hash da senha com PHP:
--      php -r "echo password_hash('SUA_SENHA_AQUI', PASSWORD_BCRYPT);"
--
-- INSERT INTO users (email, password_hash, full_name, role, is_active)
-- VALUES
--   ('medico.exemplo@dominio.com',
--    '<HASH_BCRYPT_GERADO>',
--    'Nome do Profissional',
--    'doctor', 1)
-- ON DUPLICATE KEY UPDATE
--   full_name = VALUES(full_name),
--   role      = VALUES(role),
--   is_active = 1;

-- ============ migration-endereco-e-telefone ============
USE sgx_db;
-- =====================================================================
-- Eu Digo X — Telefone do paciente + endereço completo
--
-- Adiciona, na tabela `patients`:
--   * phone          — telefone do paciente (obrigatório quando é "pra mim")
--   * zip_code       — CEP (somente dígitos ou no formato 00000-000)
--   * street         — rua/logradouro (preenchido pela ViaCEP, editável)
--   * number         — número do imóvel (manual)
--   * complement     — apto/bloco/etc (opcional)
--   * neighborhood   — bairro (ViaCEP, editável)
--   * city           — cidade (ViaCEP, editável)
--   * state          — UF (ViaCEP, editável)
--
-- COMO RODAR (no MAMP):
--   phpMyAdmin → sgx_db → aba SQL → cola tudo → Executar
--
-- Atenção: o MySQL do MAMP (5.7) NÃO aceita "ADD COLUMN IF NOT EXISTS".
-- Por isso esse script é direto. Se já rodou antes, vai dar erro
-- "Duplicate column name" — basta ignorar (significa que já está aplicado).
-- =====================================================================

ALTER TABLE patients
  ADD COLUMN phone         VARCHAR(20)  NULL  COMMENT 'Telefone do paciente' AFTER cpf,
  ADD COLUMN zip_code      VARCHAR(10)  NULL  COMMENT 'CEP' AFTER phone,
  ADD COLUMN street        VARCHAR(180) NULL  COMMENT 'Rua / logradouro' AFTER zip_code,
  ADD COLUMN number        VARCHAR(20)  NULL  COMMENT 'Número do imóvel' AFTER street,
  ADD COLUMN complement    VARCHAR(80)  NULL  COMMENT 'Complemento (apto, bloco...)' AFTER number,
  ADD COLUMN neighborhood  VARCHAR(120) NULL  COMMENT 'Bairro' AFTER complement,
  ADD COLUMN city          VARCHAR(120) NULL  COMMENT 'Cidade' AFTER neighborhood,
  ADD COLUMN state         CHAR(2)      NULL  COMMENT 'UF' AFTER city;

-- =====================================================================
-- Confira com:
--   SHOW COLUMNS FROM patients LIKE 'phone';
--   SHOW COLUMNS FROM patients LIKE 'zip_code';
-- =====================================================================

-- ============ migration-historico-familiar ============
USE sgx_db;
-- =====================================================================
-- Eu Digo X — Histórico familiar (texto livre, sem peso no score)
--
-- Adiciona apenas UMA coluna em `patients` para guardar a descrição
-- livre que o paciente/responsável escreve sobre histórico familiar.
-- Esse campo NÃO entra no cálculo do score — é só pra clínica ver
-- no prontuário.
--
-- COMO RODAR (no MAMP):
--   phpMyAdmin → selecione o banco do projeto → aba "SQL" →
--   cole tudo → Executar
--
-- (No setup do zero, a coluna ainda nao existe, entao o ADD direto funciona.)
-- =====================================================================

ALTER TABLE patients
  ADD COLUMN family_history_notes TEXT NULL
    COMMENT 'Histórico familiar relatado pelo paciente/responsável (texto livre, sem peso no score).'
  AFTER guardian_email;

-- =====================================================================
-- Confira com:
--   SHOW COLUMNS FROM patients LIKE 'family_history_notes';
-- =====================================================================

-- ============ migration-meeting-link ============
USE sgx_db;
-- =====================================================================
-- Eu Digo X — Campo "Link da reunião" nas consultas
--
-- Adiciona uma coluna meeting_link em appointments. A recepção
-- preenche esse link (ex: Google Meet) pela tela de Calendário,
-- e tanto a paciente quanto a profissional veem nos painéis deles.
--
-- VARCHAR(500) é mais que suficiente — URLs do Google Meet têm
-- ~40 caracteres; do Zoom uns 80; do Teams pode passar de 200.
--
-- COMO RODAR (no MAMP):
--   phpMyAdmin → sgx_db → aba SQL → cola → Executar
--
-- ⚠️ Se já rodou antes, vai dar "Duplicate column name" — pode
-- ignorar (significa que já tá aplicado).
-- =====================================================================

ALTER TABLE appointments
  ADD COLUMN meeting_link VARCHAR(500) NULL
    COMMENT 'URL da reunião online (Google Meet, Zoom, etc.)'
    AFTER location;

-- Confira com:
--   SHOW COLUMNS FROM appointments LIKE 'meeting_link';

-- ============ migration-motivo-consulta ============
USE sgx_db;
-- =====================================================================
-- Migração: motivo da consulta (Leva C)
-- =====================================================================
-- Adiciona em appointments:
--   visit_reason        ENUM('first_visit','return','other')  — o motivo
--   visit_reason_other  VARCHAR(255)                          — texto livre (quando 'other')
--
-- Ambas NULL: as consultas que já existem continuam válidas (motivo
-- "não informado"). Novos agendamentos exigem o motivo (validado na API).
--
-- IDEMPOTENTE: checa o INFORMATION_SCHEMA antes de adicionar, então pode
-- rodar várias vezes sem dar "Duplicate column".
-- Banco: sgx_db
-- =====================================================================

-- 1) visit_reason
SET @c1 := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'appointments'
    AND COLUMN_NAME  = 'visit_reason'
);
SET @s1 := IF(@c1 = 0,
  "ALTER TABLE appointments
     ADD COLUMN visit_reason ENUM('first_visit','return','other') NULL
       COMMENT 'Motivo da consulta: primeira consulta / retorno / outro'
       AFTER notes",
  'DO 0');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

-- 2) visit_reason_other
SET @c2 := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'appointments'
    AND COLUMN_NAME  = 'visit_reason_other'
);
SET @s2 := IF(@c2 = 0,
  "ALTER TABLE appointments
     ADD COLUMN visit_reason_other VARCHAR(255) NULL
       COMMENT 'Texto livre do motivo quando visit_reason = other'
       AFTER visit_reason",
  'DO 0');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

-- Confira com:
--   SHOW COLUMNS FROM appointments LIKE 'visit_reason%';

-- ============ migration-anotacoes-consulta ============
USE sgx_db;
-- =====================================================================
-- Migração: anotações clínicas por consulta (Leva 2)
-- =====================================================================
-- Cria a tabela appointment_notes.
--
-- Modelo APPEND-ONLY (só cresce): cada anotação de uma consulta é uma
-- linha. Nada é apagado nem reescrito — cada "Revisar" grava uma linha
-- nova, com data e autor. Isso preserva o histórico do prontuário.
--
-- IDEMPOTENTE: pode rodar várias vezes sem duplicar (IF NOT EXISTS).
-- Banco: sgx_db
-- =====================================================================

CREATE TABLE IF NOT EXISTS appointment_notes (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id  BIGINT UNSIGNED NOT NULL COMMENT 'Consulta a que a anotação pertence',
  author_user_id  BIGINT UNSIGNED NULL     COMMENT 'Profissional que escreveu (NULL se o usuário for removido)',
  note            TEXT NOT NULL            COMMENT 'Texto da anotação clínica',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_appt_notes_appointment
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_notes_author
    FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_appt_notes_appointment (appointment_id),
  INDEX idx_appt_notes_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============ migration-uploads-e-socioeconomica ============
USE sgx_db;
-- =====================================================================
-- Eu Digo X — Uploads + Triagem socioeconômica
--
-- Cria 2 tabelas novas:
--   1) patient_uploads — arquivos (fotos do paciente + requisição médica)
--   2) socioeconomic_assessments — respostas da triagem socioeconômica
--
-- Não mexe em nada que já existe.
--
-- COMO RODAR (no MAMP):
--   phpMyAdmin → selecione o banco sgx_db → aba "SQL" →
--   cole tudo → Executar
--
-- Esse script só CRIA tabelas com IF NOT EXISTS, então é seguro
-- rodar de novo sem quebrar nada.
-- =====================================================================

-- ---------------------------------------------------------------------
-- PATIENT_UPLOADS — fotos do paciente e requisição médica
-- O arquivo em si fica em backend/uploads/, no disco. Aqui guardamos
-- só o caminho e os metadados (quem mandou, quando, tipo, etc.).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS patient_uploads (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id      BIGINT UNSIGNED NOT NULL,
  kind            ENUM('photo_front', 'photo_side', 'medical_request') NOT NULL
                  COMMENT 'photo_front=foto de frente, photo_side=foto de lado, medical_request=requisição médica',
  original_name   VARCHAR(255) NOT NULL COMMENT 'Nome original do arquivo no upload',
  stored_path     VARCHAR(500) NOT NULL COMMENT 'Caminho do arquivo dentro de backend/uploads/',
  mime_type       VARCHAR(120) NOT NULL,
  size_bytes      INT UNSIGNED NOT NULL,
  uploaded_by_user_id BIGINT UNSIGNED NULL COMMENT 'Quem fez upload (paciente, responsável, profissional)',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME NULL,
  CONSTRAINT fk_uploads_patient
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_uploads_uploader
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_uploads_patient (patient_id),
  INDEX idx_uploads_kind    (kind)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SOCIOECONOMIC_ASSESSMENTS — triagem socioeconômica
-- 7 perguntas. A clínica vê as respostas + a renda per capita calculada
-- (renda ÷ pessoas) e decide MANUALMENTE se oferece apoio.
-- O sistema NÃO decide nada com isso.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS socioeconomic_assessments (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id          BIGINT UNSIGNED NOT NULL,

  -- 1. Quantas pessoas moram na casa (incl. o paciente)
  household_size      TINYINT UNSIGNED NOT NULL COMMENT '1..30',

  -- 2. Renda mensal total da família (faixas em salários mínimos)
  income_range        ENUM('up_to_1', '1_to_2', '2_to_3', 'above_3') NOT NULL,

  -- 3. Recebe benefício social?
  receives_benefit    TINYINT(1) NOT NULL DEFAULT 0,
  benefit_details     VARCHAR(255) NULL COMMENT 'Quais benefícios (Bolsa Família, BPC...)',

  -- 4. Situação de trabalho do provedor
  provider_work_status ENUM('formal', 'informal', 'unemployed', 'retired') NOT NULL,

  -- 5. Possui plano de saúde?
  has_health_plan     TINYINT(1) NOT NULL DEFAULT 0,

  -- 6. Escolaridade do responsável / provedor
  provider_education  ENUM(
    'fundamental_incomplete', 'fundamental_complete',
    'high_school_incomplete', 'high_school_complete',
    'higher_incomplete',      'higher_complete',
    'postgrad_incomplete',    'postgrad_complete'
  ) NOT NULL,

  -- 7. Observações adicionais (opcional)
  observations        TEXT NULL,

  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_socio_patient
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  INDEX idx_socio_patient (patient_id)
) ENGINE=InnoDB;

-- =====================================================================
-- Confira com:
--   SHOW TABLES LIKE 'patient_uploads';
--   SHOW TABLES LIKE 'socioeconomic_assessments';
-- =====================================================================

-- ============ migration-vinculo-triagem ============
USE sgx_db;
-- =====================================================================
-- Migração: vincular anexos e socioeconômica à triagem (Leva D1)
-- =====================================================================
-- Adiciona screening_id em:
--   - patient_uploads
--   - socioeconomic_assessments
-- (com FK para screenings e índice).
--
-- NULL = registro antigo (legado), antes de existir o vínculo.
-- IDEMPOTENTE: cada passo checa o INFORMATION_SCHEMA antes de aplicar,
-- então pode rodar várias vezes sem erro.
-- Banco: sgx_db
-- =====================================================================

-- ========== patient_uploads ==========

-- coluna
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='patient_uploads' AND COLUMN_NAME='screening_id');
SET @s := IF(@c=0,
  "ALTER TABLE patient_uploads ADD COLUMN screening_id BIGINT UNSIGNED NULL
     COMMENT 'Triagem à qual o anexo pertence (NULL = legado)' AFTER patient_id",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- índice
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='patient_uploads' AND INDEX_NAME='idx_uploads_screening');
SET @s := IF(@c=0,
  "ALTER TABLE patient_uploads ADD INDEX idx_uploads_screening (screening_id)",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- FK
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='patient_uploads' AND CONSTRAINT_NAME='fk_uploads_screening');
SET @s := IF(@c=0,
  "ALTER TABLE patient_uploads ADD CONSTRAINT fk_uploads_screening
     FOREIGN KEY (screening_id) REFERENCES screenings(id) ON DELETE SET NULL",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ========== socioeconomic_assessments ==========

-- coluna
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='socioeconomic_assessments' AND COLUMN_NAME='screening_id');
SET @s := IF(@c=0,
  "ALTER TABLE socioeconomic_assessments ADD COLUMN screening_id BIGINT UNSIGNED NULL
     COMMENT 'Triagem à qual a socioeconômica pertence (NULL = legado)' AFTER patient_id",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- índice
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='socioeconomic_assessments' AND INDEX_NAME='idx_socio_screening');
SET @s := IF(@c=0,
  "ALTER TABLE socioeconomic_assessments ADD INDEX idx_socio_screening (screening_id)",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- FK
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='socioeconomic_assessments' AND CONSTRAINT_NAME='fk_socio_screening');
SET @s := IF(@c=0,
  "ALTER TABLE socioeconomic_assessments ADD CONSTRAINT fk_socio_screening
     FOREIGN KEY (screening_id) REFERENCES screenings(id) ON DELETE SET NULL",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Confira com:
--   SHOW COLUMNS FROM patient_uploads LIKE 'screening_id';
--   SHOW COLUMNS FROM socioeconomic_assessments LIKE 'screening_id';

-- ============ SEED: indicadores ============
USE sgx_db;
-- =====================================================================
-- SEED MÍNIMO — apenas o catálogo dos 12 indicadores
-- (sem dados de paciente/profissional, conforme solicitado)
-- =====================================================================
-- Pesos provisórios: distribuídos para que o score caia no range esperado.
-- AJUSTAR conforme estudo clínico que vocês utilizarem.
-- =====================================================================

INSERT INTO indicators
  (code, display_name, lay_label, clinical_tooltip, category, weight_male, weight_female, applies_to, display_order)
VALUES
  -- DESENVOLVIMENTO (4)
  ('SPEECH_DELAY', 'Atraso na fala',
   'Demorou mais que outras crianças para começar a falar',
   'Atraso significativo no desenvolvimento da linguagem oral, comum em crianças com SXF.',
   'development', 0.0900, 0.0700, 'both', 10),

  ('ATTENTION_DEFICIT', 'Déficit de atenção',
   'Dificuldade para se concentrar e manter o foco',
   'Dificuldade persistente de atenção, comumente associada à SXF, podendo se confundir com TDAH.',
   'development', 0.0800, 0.0900, 'both', 20),

  ('LEARNING_DIFFICULTY', 'Dificuldades de aprendizagem',
   'Dificuldades para aprender na escola ou acompanhar o conteúdo',
   'Atrasos cognitivos e dificuldades acadêmicas, frequentemente o primeiro sinal em mulheres com SXF.',
   'development', 0.0800, 0.1000, 'both', 30),

  ('INTELLECTUAL_DELAY', 'Atraso no desenvolvimento intelectual',
   'Desenvolvimento mais lento que o esperado para a idade',
   'Atraso global no desenvolvimento neuropsicomotor, principal manifestação da SXF.',
   'development', 0.1000, 0.0700, 'both', 40),

  -- COMPORTAMENTAIS (4)
  ('GAZE_AVOIDANCE', 'Evita contato visual',
   'Costuma desviar o olhar quando alguém olha nos olhos',
   'Esquiva ao contato visual direto, comum em pessoas com SXF e relacionada à ansiedade social.',
   'behavioral', 0.0800, 0.0700, 'both', 50),

  ('TOUCH_AVOIDANCE', 'Evita contato físico',
   'Não gosta de abraços ou de ser tocado',
   'Aversão tátil ou esquiva de contato físico, manifestação sensorial comum na SXF.',
   'behavioral', 0.0700, 0.0700, 'both', 60),

  ('SOCIAL_ANXIETY', 'Ansiedade social',
   'Fica muito ansioso(a) em situações com outras pessoas',
   'Ansiedade marcante em contextos sociais, comum em portadores e portadoras da SXF.',
   'behavioral', 0.0700, 0.0900, 'both', 70),

  ('STEREOTYPIES', 'Movimentos repetitivos',
   'Faz movimentos repetitivos com as mãos ou corpo (ex: balançar, bater palmas)',
   'Estereotipias motoras: movimentos repetitivos sem propósito aparente, comuns na SXF.',
   'behavioral', 0.0700, 0.0700, 'both', 80),

  -- FÍSICOS (4)
  ('LONG_FACE', 'Rosto alongado',
   'Rosto comprido e estreito',
   'Face alongada com mandíbula proeminente, característica fenotípica clássica da SXF.',
   'physical', 0.0800, 0.0600, 'both', 90),

  ('PROMINENT_EARS', 'Orelhas proeminentes',
   'Orelhas grandes ou que ficam para fora da cabeça',
   'Orelhas grandes e/ou em abano, traço fenotípico comum na SXF.',
   'physical', 0.0900, 0.0700, 'both', 100),

  ('JOINT_HYPERMOBILITY', 'Articulações muito flexíveis',
   'Dedos, pulsos ou outras articulações dobram além do normal',
   'Hipermobilidade articular generalizada, achado físico frequente na SXF.',
   'physical', 0.0700, 0.0800, 'both', 110),

  ('MACROORCHIDISM', 'Testículos aumentados',
   'Testículos com tamanho acima do esperado (geralmente após a puberdade)',
   'Macroorquidismo: aumento do volume testicular, característica fenotípica marcante em homens com SXF pós-puberdade.',
   'physical', 0.1000, 0.0000, 'M', 120);

-- =====================================================================
-- CONTAS DE TESTE (demonstração) — senha de todas: EuDigoX2026!
-- Apenas para uso local/demonstração. NÃO são dados reais.
-- =====================================================================
INSERT INTO users (email, password_hash, full_name, role, is_active) VALUES
  ('admin@eudigox.test',   '$2b$10$c1CuAO.0erZZlFFRL7B9/OI1DMH8IRDj37hu9Es0x7P1tE6DlaZ86', 'Administrador Demo', 'admin',        1),
  ('medica@eudigox.test',  '$2b$10$c1CuAO.0erZZlFFRL7B9/OI1DMH8IRDj37hu9Es0x7P1tE6DlaZ86', 'Dra. Demonstração',  'doctor',       1),
  ('recepcao@eudigox.test','$2b$10$c1CuAO.0erZZlFFRL7B9/OI1DMH8IRDj37hu9Es0x7P1tE6DlaZ86', 'Recepção Demo',      'receptionist', 1),
  ('paciente@eudigox.test','$2b$10$c1CuAO.0erZZlFFRL7B9/OI1DMH8IRDj37hu9Es0x7P1tE6DlaZ86', 'Paciente Demo',      'patient',      1);

-- Perfil de paciente vinculado ao usuário paciente@eudigox.test
INSERT INTO patients (user_id, full_name, birth_date, biological_sex)
SELECT id, 'Paciente Demo', '2015-05-10', 'M' FROM users WHERE email='paciente@eudigox.test';
