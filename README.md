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
└── sql/             Script de estrutura (schema) e migrações
docs/                Documentação (modelagem, implantação, tutorial, diagramas)
```

## Como executar localmente

Pré-requisitos: PHP 8, MySQL 8 e um servidor web (recomenda-se o MAMP).

1. Clone o repositório e coloque a pasta `sgx` no diretório público do servidor.
2. Inicie o Apache e o MySQL.
3. Crie o banco `sgx_db` e execute `sql/schema.sql` e as migrações da pasta
   `sql/` na ordem cronológica.
4. Acesse `http://localhost/sgx/frontend/pages/index.html` no navegador.

As credenciais do banco são lidas de variáveis de ambiente; na ausência delas
(ambiente local), o sistema usa os valores padrão do MAMP, sem configuração
adicional.

## Implantação em produção (Microsoft Azure)

O sistema está publicado na nuvem Microsoft Azure, com a seguinte arquitetura:

- **Azure App Service** (Linux, PHP 8.3): hospeda a aplicação, com "Sempre
  Ativado" (Always On) e redirecionamento automático para HTTPS.
- **Azure Database for MySQL — Flexible Server** (MySQL 8.4): hospeda o banco
  `sgx_db`, com conexão obrigatória por SSL e firewall restrito.
- **Variáveis de ambiente (App Settings)**: as credenciais do banco não ficam
  no código — são lidas de variáveis de ambiente, atendendo à LGPD.

Resumo do processo de implantação:

1. Provisionar o banco MySQL no Azure e importar o `schema.sql` e as migrações
   (script consolidado) via cliente MySQL com SSL.
2. Criar o App Service (PHP 8.3, Linux) e cadastrar as variáveis de ambiente
   (host, usuário, senha, `DB_SSL`, `UPLOAD_DIR`, entre outras).
3. Publicar o código pelo Azure Cloud Shell e ativar "Somente HTTPS".

O passo a passo completo, com requisitos e versões, está em
`docs/Documento-Implantacao.pdf`.

## Documentação

- Documento de Requisitos e Especificação Técnica
- Documentação do Banco de Dados (modelagem conceitual, lógica e física)
- Documento Técnico de Implantação
- Tutorial de Uso para profissionais de saúde

## Segurança e LGPD

Senhas armazenadas como hash bcrypt; consultas parametrizadas (PDO); conexões
cifradas (HTTPS e SSL no banco); credenciais fora do código (variáveis de
ambiente); exclusão lógica (soft delete) e trilha de auditoria.

## Autores

- Isabella Schwab
- Leonardo Simioni Torquato
- Rafael Engel
- Vicente Nogueira

## Licença

Distribuído sob a licença MIT. Consulte o arquivo `LICENSE` para mais detalhes.
