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
