<?php
require_once __DIR__ . '/../core/bootstrap.php';

Response::success([
    'name'    => APP_NAME,
    'version' => APP_VERSION,
    'status'  => 'ok',
    'time'    => date('c'),
    'endpoints' => [
        // Autenticação
        'POST   /v1/auth/login',
        'POST   /v1/auth/logout',
        'GET    /v1/auth/me',

        // Paciente
        'POST   /v1/patients/register',
        'GET    /v1/patients/get?id={id}',
        'GET    /v1/patients/me',

        // Triagens
        'GET    /v1/screenings/list',
        'GET    /v1/screenings/get?id={id}',
        'PATCH  /v1/screenings/review',

        // Exames
        'POST   /v1/exams/register',

        // Indicadores
        'GET    /v1/indicators/list',

        // Admin (gestão de profissionais)
        'GET    /v1/users/list',
        'POST   /v1/users/create',
        'PATCH  /v1/users/update',
    ],
]);
