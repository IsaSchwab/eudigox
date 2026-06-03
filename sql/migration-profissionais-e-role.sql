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
