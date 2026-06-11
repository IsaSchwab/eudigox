<?php
/**
 * Eu Digo X — Configuração principal (EXEMPLO)
 *
 * COMO USAR: copie este arquivo para config.php (na mesma pasta) e,
 * se precisar, ajuste os valores. Com o MAMP padrão, já funciona assim.
 *
 *   No Mac:     cp config.example.php config.php
 *   No Windows: copie e renomeie para config.php
 */

// Ambiente: 'development' | 'production'
define('APP_ENV', 'development');
define('APP_NAME', 'SGX');
define('APP_VERSION', '1.0.0');

// ===== Banco de dados (defaults do MAMP) =====
define('DB_HOST', 'localhost');
// MAMP no Mac usa a porta 8889; o MAMP no Windows usa 3306.
// Não precisa se preocupar: o sistema tenta as duas portas automaticamente.
define('DB_PORT', '8889');
define('DB_NAME', 'sgx_db');
define('DB_USER', 'root');
define('DB_PASS', 'root');         // senha padrão do MAMP é 'root'
define('DB_CHARSET', 'utf8mb4');

// Sessão / autenticação
define('PASSWORD_MIN_LENGTH', 8);

// ===== Validação de domínio do e-mail (MX/A record via DNS) =====
// Em ambiente local (MAMP) deixe FALSE — o DNS dentro do PHP do MAMP é
// lento e pode travar o cadastro. Em produção (Azure) pode ficar TRUE.
define('EMAIL_DOMAIN_CHECK_ENABLED', false);
define('EMAIL_DOMAIN_CHECK_TIMEOUT_SECONDS', 2);

// CORS — só importa se front e back estiverem em portas/domínios diferentes.
// Servindo tudo pelo MAMP (mesma origem), na prática não é usado.
define('CORS_ALLOWED_ORIGINS', [
    'http://localhost',
    'http://localhost:80',
    'http://127.0.0.1',
    'http://127.0.0.1:80',
]);

// ===== Limiares de score por sexo =====
// Valores de referência usados pelo projeto (também citados no
// backend/core/ScoreCalculator.php). Os pesos dos indicadores estão no
// sql/setup.sql e são PROVISÓRIOS — ajuste ambos conforme o estudo
// clínico validado com o Instituto.
define('SCORE_THRESHOLD_MALE',   0.56);
define('SCORE_THRESHOLD_FEMALE', 0.55);

// Erros visíveis apenas em dev
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Timezone
date_default_timezone_set('America/Sao_Paulo');
