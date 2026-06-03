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
-- É SEGURO rodar de novo: usa "IF NOT EXISTS".
-- =====================================================================

ALTER TABLE patients
  ADD COLUMN IF NOT EXISTS family_history_notes TEXT NULL
    COMMENT 'Histórico familiar relatado pelo paciente/responsável (texto livre, sem peso no score).'
  AFTER guardian_email;

-- =====================================================================
-- Confira com:
--   SHOW COLUMNS FROM patients LIKE 'family_history_notes';
-- =====================================================================
