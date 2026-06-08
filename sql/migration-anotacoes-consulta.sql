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
