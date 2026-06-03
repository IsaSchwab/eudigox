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
