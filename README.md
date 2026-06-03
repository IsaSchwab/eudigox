# 🦋 Eu Digo X — Sistema de Triagem da Síndrome do X Frágil

Sistema web para apoio à triagem precoce da Síndrome do X Frágil (SXF),
desenvolvido como Trabalho de Conclusão de Curso (PUCPR) em parceria com o
**Instituto Buko Kaesemodel**.

- **Frontend:** HTML + CSS + JavaScript (sem framework)
- **Backend:** PHP + MySQL (API em JSON)
- **Ambiente de desenvolvimento:** MAMP (recomendado) / XAMPP / Laragon

---

## 🗂 Estrutura do projeto

```
eudigox/
├── frontend/              → camada de apresentação
│   ├── pages/             → todas as telas HTML
│   └── assets/            → css/, js/, img/
├── backend/               → API PHP
│   ├── api/               → endpoints (cada arquivo é um endpoint)
│   │   ├── auth/          → login, logout, me
│   │   ├── patients/      → register, get, list, me
│   │   ├── screenings/    → list, get, review, edit-answers
│   │   ├── appointments/  → agenda e consultas
│   │   ├── exams/         → registro de exame molecular
│   │   ├── uploads/       → fotos clínicas do paciente
│   │   ├── users/         → gestão de profissionais (admin)
│   │   └── indicators/    → catálogo de indicadores
│   ├── config/            → config.example.php, database.php
│   └── core/              → Auth, Request, Response, Validator,
│                            ScoreCalculator, Audit, bootstrap
└── sql/                   → schema do banco + migrations
```

---

## 🚀 Como rodar localmente (MAMP no Mac)

### 1. Clone para a pasta do servidor

```bash
cd /Applications/MAMP/htdocs
git clone https://github.com/SEU_USUARIO/eudigox.git sgx
```

### 2. Crie sua configuração local

```bash
cd sgx/backend/config
cp config.example.php config.php
```

Edite `config.php` com as credenciais do seu MySQL e os parâmetros
clínicos (veja a seção *Dados clínicos* abaixo).

### 3. Ligue o MAMP

Abra o MAMP → **Start Servers** → espere Apache e MySQL ficarem verdes.

### 4. Importe o banco de dados

1. **Open WebStart Page** → **Tools → phpMyAdmin**
2. Aba **Importar** → escolha `sql/schema.sql` → **Executar**
3. Rode as migrations da pasta `sql/` (são idempotentes)
4. Rode o seed privado de indicadores (veja *Dados clínicos*)

### 5. Pronto

- **Landing:** http://localhost/sgx/frontend/pages/index.html
- **Login:** http://localhost/sgx/frontend/pages/login.html
- **Triagem (paciente):** http://localhost/sgx/frontend/pages/triagem.html

---

## 🧬 Dados clínicos (não versionados)

Os **pesos dos indicadores** e os **limiares de score** são definidos pela
equipe clínica do Instituto Buko Kaesemodel e **não são publicados neste
repositório**. Eles vivem em:

| O quê | Onde fica (local, fora do git) |
|---|---|
| Pesos dos 12 indicadores | `sql/seeds-local/seed-indicators.sql` |
| Limiares de score (M/F) | `backend/config/config.php` |
| Usuários reais | `sql/seeds-local/` |

Para obter esses arquivos, entre em contato com a equipe do projeto.
Sem o seed, o sistema funciona, mas a triagem não calcula score.

---

## 🔐 Autenticação e segurança

- **Sessão PHP nativa** com cookies httpOnly (sem tokens no localStorage)
- Senhas com hash **bcrypt** (`password_hash`)
- Consultas com **PDO + prepared statements** (proteção contra SQL injection)
- **Trilha de auditoria** (`backend/core/Audit.php`) registra ações sensíveis

### LGPD

O sistema trata dados pessoais sensíveis (saúde). Por isso este repositório
**não contém**: fotos ou dados de pacientes, logs, credenciais ou seeds com
dados reais — tudo isso é excluído via `.gitignore`. Uploads de pacientes
ficam fora do controle de versão e protegidos por `.htaccess`.

---

## 👥 Perfis de acesso

| Perfil | Pode acessar |
|--------|--------------|
| `patient` | Painel próprio, prontuário próprio, agendamento |
| `nurse` | Dashboard clínico, prontuário (sem registrar exame) |
| `receptionist` | Agenda, calendário e consultas |
| `doctor` | Dashboard clínico, prontuário, registrar exame molecular |
| `admin` | Gestão de profissionais + tudo do médico |

---

## 🧮 Score de triagem

- Cada indicador tem peso por sexo (M/F), definido pela equipe clínica
- Score = soma dos pesos dos indicadores respondidos com **"Sim"**
- Score ≥ limiar → encaminhamento para exame molecular (PCR / Southern Blotting)

Implementado em `backend/core/ScoreCalculator.php`. Os valores numéricos
vêm do banco e da configuração local (ver *Dados clínicos*).

---

## 👥 Equipe

Leonardo Simioni Torquato · Rafael Engel · Vicente Nogueira · Isabella Schwab

**Parceria:** Instituto Buko Kaesemodel · PUCPR
