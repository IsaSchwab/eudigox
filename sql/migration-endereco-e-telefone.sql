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
