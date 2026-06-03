<?php
/**
 * Response — helpers para respostas JSON padronizadas da API.
 * 
 * Formato consistente:
 *   { "data": ..., "meta": ... }   sucesso
 *   { "error": "...", "code": "..." }   erro
 */

class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success($data = null, array $meta = [], int $status = 200): void
    {
        $payload = ['data' => $data];
        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }
        self::json($payload, $status);
    }

    public static function created($data = null): void
    {
        self::success($data, [], 201);
    }

    public static function noContent(): void
    {
        http_response_code(204);
        exit;
    }

    public static function error(string $message, int $status = 400, ?string $code = null, array $details = []): void
    {
        $payload = ['error' => $message];
        if ($code !== null)        $payload['code']    = $code;
        if (!empty($details))      $payload['details'] = $details;
        self::json($payload, $status);
    }

    public static function badRequest(string $message = 'Requisição inválida.', array $details = []): void
    {
        self::error($message, 400, 'BAD_REQUEST', $details);
    }

    public static function unauthorized(string $message = 'Não autenticado.'): void
    {
        self::error($message, 401, 'UNAUTHORIZED');
    }

    public static function forbidden(string $message = 'Acesso negado.'): void
    {
        self::error($message, 403, 'FORBIDDEN');
    }

    public static function notFound(string $message = 'Recurso não encontrado.'): void
    {
        self::error($message, 404, 'NOT_FOUND');
    }

    public static function methodNotAllowed(string $message = 'Método HTTP não permitido.'): void
    {
        self::error($message, 405, 'METHOD_NOT_ALLOWED');
    }

    public static function conflict(string $message): void
    {
        self::error($message, 409, 'CONFLICT');
    }

    public static function unprocessable(string $message, array $details = []): void
    {
        self::error($message, 422, 'UNPROCESSABLE_ENTITY', $details);
    }

    public static function serverError(string $message = 'Erro interno do servidor.'): void
    {
        self::error($message, 500, 'INTERNAL_SERVER_ERROR');
    }
}
