<?php
/**
 * Validator — validação simples e expressiva.
 * 
 * Uso:
 *   $v = new Validator($data);
 *   $v->required('email')->email('email');
 *   $v->required('password')->minLength('password', 8);
 *   if ($v->fails()) Response::unprocessable('Dados inválidos.', $v->errors());
 */

class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    private function get(string $field)
    {
        return $this->data[$field] ?? null;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function required(string $field, ?string $message = null): self
    {
        $value = $this->get($field);
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $this->addError($field, $message ?? "O campo '{$field}' é obrigatório.");
        }
        return $this;
    }

    public function email(string $field, ?string $message = null): self
    {
        $value = $this->get($field);
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, $message ?? "E-mail inválido.");
        }
        return $this;
    }

    public function minLength(string $field, int $min, ?string $message = null): self
    {
        $value = $this->get($field);
        if ($value !== null && mb_strlen((string)$value) < $min) {
            $this->addError($field, $message ?? "Deve ter pelo menos {$min} caracteres.");
        }
        return $this;
    }

    public function maxLength(string $field, int $max, ?string $message = null): self
    {
        $value = $this->get($field);
        if ($value !== null && mb_strlen((string)$value) > $max) {
            $this->addError($field, $message ?? "Deve ter no máximo {$max} caracteres.");
        }
        return $this;
    }

    public function in(string $field, array $allowed, ?string $message = null): self
    {
        $value = $this->get($field);
        if ($value !== null && !in_array($value, $allowed, true)) {
            $this->addError($field, $message ?? "Valor inválido.");
        }
        return $this;
    }

    public function date(string $field, string $format = 'Y-m-d', ?string $message = null): self
    {
        $value = $this->get($field);
        if ($value !== null && $value !== '') {
            $d = DateTime::createFromFormat($format, $value);
            if (!$d || $d->format($format) !== $value) {
                $this->addError($field, $message ?? "Data inválida (formato esperado: {$format}).");
            }
        }
        return $this;
    }

    /**
     * Valida CPF brasileiro pelo algoritmo dos dígitos verificadores.
     * Aceita o CPF com ou sem pontuação ("000.000.000-00" ou "00000000000").
     * Não confere com a base da Receita — só verifica que a estrutura está correta.
     */
    public function cpf(string $field, ?string $message = null): self
    {
        $value = $this->get($field);
        if ($value === null || $value === '') return $this;

        $digits = preg_replace('/\D/', '', (string)$value);

        $fail = function () use ($field, $message) {
            $this->addError($field, $message ?? 'CPF inválido. Confira os números.');
        };

        // Precisa ter exatamente 11 dígitos
        if (strlen($digits) !== 11) { $fail(); return $this; }
        // Não pode ser todos iguais (111.111.111-11 etc.)
        if (preg_match('/^(\d)\1{10}$/', $digits)) { $fail(); return $this; }

        // 1º dígito verificador
        $sum = 0;
        for ($i = 0; $i < 9; $i++) $sum += (int)$digits[$i] * (10 - $i);
        $d1 = (10 * $sum) % 11;
        if ($d1 === 10) $d1 = 0;
        if ($d1 !== (int)$digits[9]) { $fail(); return $this; }

        // 2º dígito verificador
        $sum = 0;
        for ($i = 0; $i < 10; $i++) $sum += (int)$digits[$i] * (11 - $i);
        $d2 = (10 * $sum) % 11;
        if ($d2 === 10) $d2 = 0;
        if ($d2 !== (int)$digits[10]) { $fail(); return $this; }

        return $this;
    }

    /**
     * Verifica se o domínio do e-mail aceita correspondência (tem MX ou A record).
     * Pega erros tipo "joao@gmaill.com" (com 2 L) ou "joao@inexistente.xyz".
     * Não garante que a CAIXA existe — só que o domínio é capaz de receber e-mail.
     *
     * Comportamento:
     *   - Se EMAIL_DOMAIN_CHECK_ENABLED estiver desligada, pula a checagem
     *     (failure-open). Útil pro MAMP, onde o DNS local pode travar o PHP.
     *   - Mesmo com a flag ligada, usa timeout curto (configurável por
     *     EMAIL_DOMAIN_CHECK_TIMEOUT_SECONDS, padrão 2s). Se o DNS demorar
     *     mais que isso, deixa passar sem reclamar — melhor um cadastro
     *     com e-mail talvez errado do que um sistema travado pra todo mundo.
     *   - Em ambientes onde checkdnsrr não existe, também deixa passar.
     */
    public function emailDomain(string $field, ?string $message = null): self
    {
        $value = $this->get($field);
        if ($value === null || $value === '') return $this;
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) return $this; // o email() já cuida disso

        // Se a checagem está desligada por config, sai sem fazer nada.
        if (!defined('EMAIL_DOMAIN_CHECK_ENABLED') || !EMAIL_DOMAIN_CHECK_ENABLED) {
            return $this;
        }
        if (!function_exists('checkdnsrr')) return $this;

        $domain = substr((string)$value, strrpos((string)$value, '@') + 1);
        if ($domain === '' || $domain === false) return $this;

        // Limita o tempo total do socket (afeta as consultas DNS via getmxrr/checkdnsrr).
        $timeout = defined('EMAIL_DOMAIN_CHECK_TIMEOUT_SECONDS')
            ? (int)EMAIL_DOMAIN_CHECK_TIMEOUT_SECONDS
            : 2;
        $oldTimeout = (int)ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', (string)max(1, $timeout));

        try {
            // Mede o tempo gasto. Se passar do timeout, ignora o resultado
            // e considera o domínio "ok" (failure-open).
            $start = microtime(true);
            $hasMx = @checkdnsrr($domain, 'MX');
            $elapsed = microtime(true) - $start;

            if ($elapsed > $timeout) {
                // DNS lento demais — não bloqueia o cadastro.
                return $this;
            }

            if (!$hasMx) {
                $hasA = @checkdnsrr($domain, 'A');
                if (!$hasA) {
                    $this->addError($field, $message ?? 'O domínio do e-mail não foi encontrado. Confira se está escrito certo.');
                }
            }
        } finally {
            @ini_set('default_socket_timeout', (string)$oldTimeout);
        }

        return $this;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
