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
