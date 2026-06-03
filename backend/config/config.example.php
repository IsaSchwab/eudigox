<?php
/**
 * Eu Digo X — Configuração principal (EXEMPLO)
 * Copie para config.php e ajuste os valores locais.
 */

// Ambiente: 'development' | 'production'
define('APP_ENV', 'development');
define('APP_NAME', 'SGX');
define('APP_VERSION', '1.0.0');

// ===== Banco de dados (defaults do MAMP) =====
define('DB_HOST', 'localhost');
define('DB_PORT', '8889');         // MAMP usa 8889 por padrão pro MySQL
define('DB_NAME', 'sgx_db');
define('DB_USER', 'root');
define('DB_PASS', 'root');         // senha padrão do MAMP é 'root'
define('DB_CHARSET', 'utf8mb4');

// Sessão / autenticação
define('PASSWORD_MIN_LENGTH', 8);

// ===== Validação de domínio do e-mail (MX/A record via DNS) =====
// Quando ligada, antes de cadastrar a gente checa se o domínio do e-mail
// (ex: "gmail.com") existe de verdade no DNS. Isso pega erros do tipo
// "joao@gmaill.com" (com 2 L), mas requer DNS rápido pra não travar.
//
// MAMP local: deixa FALSE — o DNS do macOS dentro do PHP-FCGI do MAMP é
//             lentíssimo (chega a 30s por consulta) e derruba o request
//             inteiro com "FastCGI idle timeout".
// Azure / produção: vire TRUE — lá o DNS resolve em milissegundos.
//
// Mesmo com isso TRUE, a validação tem timeout interno curto (2s) e
// "falha aberta": se o DNS demorar, deixa passar em vez de bloquear o
// cadastro do paciente.
define('EMAIL_DOMAIN_CHECK_ENABLED', false);
define('EMAIL_DOMAIN_CHECK_TIMEOUT_SECONDS', 2);

// CORS — apenas se o front e back estiverem em portas/dominios diferentes
// Como agora servimos tudo pelo MAMP no localhost:80, o navegador não envia
// header Origin (mesma origem) — então CORS na prática nem é usado.
define('CORS_ALLOWED_ORIGINS', [
    'http://localhost',
    'http://localhost:80',
    'http://127.0.0.1',
    'http://127.0.0.1:80',
]);

// Limiares de score por sexo — VALORES DEFINIDOS PELA EQUIPE CLÍNICA.
// Os valores reais não são versionados; solicite à equipe do projeto
// e preencha abaixo no seu config.php local.
define('SCORE_THRESHOLD_MALE',   0.00);  // <- substituir pelo valor real
define('SCORE_THRESHOLD_FEMALE', 0.00);  // <- substituir pelo valor real

// Erros visíveis apenas em dev
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Timezone
date_default_timezone_set('America/Manaus');
