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
