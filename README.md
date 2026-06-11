# Eu Digo X — Sistema Web para Triagem Precoce da Síndrome do X Frágil

Plataforma web que apoia profissionais de saúde e famílias na triagem clínica
precoce da Síndrome do X Frágil, desenvolvida em parceria com o Instituto Buko
Kaesemodel. Trabalho da disciplina de Experiência Criativa do curso de Ciência
da Computação da PUCPR.

## Sistema em produção

A aplicação está publicada e acessível em:
**https://eudigox-app-fnfwcpbgc2aybhcj.centralus-01.azurewebsites.net**

## Sobre o projeto

O sistema permite o cadastro de pacientes, a realização de uma triagem baseada
em indicadores clínicos, o cálculo de um escore de priorização e o
acompanhamento do paciente pela equipe clínica (recepção, enfermagem e
medicina), até o registro de exames moleculares confirmatórios.

## Funcionalidades principais

- Cadastro de pacientes e de profissionais, com controle de acesso por perfil (RBAC).
- Triagem clínica por questionário de indicadores, com cálculo de escore por sexo.
- Prontuário com anotações clínicas por consulta (histórico preservado).
- Agendamento de consultas e registro de exames moleculares.
- Triagem socioeconômica e upload de documentos (fotos e requisição médica).
- Trilha de auditoria e conformidade com a LGPD.

## Tecnologias

- Back-end: PHP 8 (API REST, acesso ao banco via PDO).
- Banco de dados: MySQL 8.
- Front-end: HTML, CSS e JavaScript.
- Nuvem: Microsoft Azure (App Service + Azure Database for MySQL).
- Controle de versão: Git / GitHub.

## Estrutura do repositório

```
sgx/
├── frontend/        Camada de apresentação (páginas, estilos, scripts)
├── backend/         Camada de processamento (API, configuração, núcleo)
└── sql/             Banco de dados
    ├── setup.sql    Script único: cria o banco inteiro e já popula (use este)
    ├── schema.sql   Estrutura base (referência)
    └── migration-*  Migrações aplicadas durante o desenvolvimento (referência)
docs/                Documentação (modelagem, implantação, tutorial, diagramas)
```

## Como executar localmente (MAMP)

Pré-requisitos: MAMP (que já traz PHP e MySQL). Funciona no Mac e no Windows.

1. **Clone** o repositório para dentro da pasta pública do servidor, com o nome
   `sgx`:

   ```
   git clone https://github.com/IsaSchwab/eudigox.git sgx
   ```

   No Windows o destino é `C:\MAMP\htdocs\sgx`; no Mac, `/Applications/MAMP/htdocs/sgx`.

2. **Crie sua configuração local** copiando o arquivo de exemplo:

   ```
   cp backend/config/config.example.php backend/config/config.php
   ```

   (No Windows, copie e renomeie `config.example.php` para `config.php`.) Com os
   valores padrão do MAMP, não é preciso ajustar mais nada.

3. **Inicie o MAMP** (Start Servers) e abra o **phpMyAdmin**.

4. **Importe o banco**: na aba *Importar*, selecione o arquivo
   **`sql/setup.sql`** e execute. Ele cria o banco `sgx_db` do zero, com a
   estrutura completa, os indicadores e as contas de teste.

5. **Acesse** no navegador:

   ```
   http://localhost/sgx/frontend/pages/login.html
   ```

### Contas de teste

Todas com a senha **`EuDigoX2026!`** (apenas para demonstração local):

| Perfil        | E-mail                    |
|---------------|---------------------------|
| Administrador | admin@eudigox.test        |
| Médica        | medica@eudigox.test       |
| Recepção      | recepcao@eudigox.test     |
| Paciente      | paciente@eudigox.test     |

## Implantação em produção (Microsoft Azure)

O sistema está publicado na nuvem Microsoft Azure, com a seguinte arquitetura:

- **Azure App Service** (Linux, PHP 8.3): hospeda a aplicação, com "Sempre
  Ativado" (Always On) e redirecionamento automático para HTTPS.
- **Azure Database for MySQL — Flexible Server** (MySQL 8.4): hospeda o banco
  `sgx_db`, com conexão obrigatória por SSL e firewall restrito.
- **Variáveis de ambiente (App Settings)**: as credenciais do banco não ficam
  no código — são lidas de variáveis de ambiente, atendendo à LGPD.

O passo a passo completo, com requisitos e versões, está em
`docs/Documento-Implantacao.pdf`.

## Documentação

- Documento de Requisitos e Especificação Técnica
- Documentação do Banco de Dados (modelagem conceitual, lógica e física)
- Documento Técnico de Implantação
- Tutorial de Uso para profissionais de saúde

## Segurança e LGPD

Senhas armazenadas como hash bcrypt; consultas parametrizadas (PDO); conexões
cifradas (HTTPS e SSL no banco); credenciais de produção fora do código
(variáveis de ambiente); exclusão lógica (soft delete) e trilha de auditoria.
Os dados reais de pacientes e os segredos de produção nunca são versionados.
Os pesos clínicos presentes em `sql/setup.sql` são provisórios, apenas para
demonstração.

## Autores

- Isabella Schwab
- Leonardo Simioni Torquato
- Rafael Engel
- Vicente Nogueira

## Licença

Distribuído sob a licença MIT. Consulte o arquivo `LICENSE` para mais detalhes.
