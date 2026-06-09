<?php
/**
 * POST /api/uploads/create.php
 *
 * Recebe um arquivo (multipart/form-data) durante o wizard de triagem.
 * Como o paciente ainda não está autenticado nesse momento, guardamos
 * o arquivo numa pasta temporária e mantemos um "token" na sessão PHP.
 * Depois, em /patients/register.php, esses tokens são processados e
 * promovidos para registros definitivos em patient_uploads.
 *
 * Campos esperados (form-data):
 *   - kind: 'photo_front' | 'photo_side' | 'medical_request'
 *   - file: o arquivo (PDF, JPG, PNG)
 *
 * Resposta de sucesso:
 *   { token: "uuid", kind, original_name, size_bytes, mime_type }
 */

require_once __DIR__ . '/../../core/bootstrap.php';

if (!Request::isMethod('POST')) {
    Response::methodNotAllowed();
}

// ---- Constantes locais ----
const MAX_BYTES = 5 * 1024 * 1024; // 5 MB
const ALLOWED_MIMES = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'application/pdf' => 'pdf',
];
const ALLOWED_KINDS = ['photo_front', 'photo_side', 'medical_request'];

$tempDir = UPLOAD_DIR . '/_pending';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0775, true);
}
if (!is_writable($tempDir)) {
    Response::serverError('Diretório de uploads não está gravável. Verifique permissões.');
}

// ---- Lê campos ----
$kind = $_POST['kind'] ?? '';
if (!in_array($kind, ALLOWED_KINDS, true)) {
    Response::badRequest('Tipo de arquivo desconhecido.');
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = match ($err) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo maior que o limite permitido.',
        UPLOAD_ERR_PARTIAL                        => 'O arquivo foi enviado parcialmente. Tente de novo.',
        UPLOAD_ERR_NO_FILE                        => 'Nenhum arquivo enviado.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Erro do servidor ao salvar o arquivo.',
        default => 'Erro ao enviar o arquivo.',
    };
    Response::badRequest($msg);
}

$file = $_FILES['file'];

// ---- Tamanho ----
if ($file['size'] > MAX_BYTES) {
    Response::badRequest('Arquivo maior que 5 MB. Tente reduzir e envie de novo.');
}

// ---- MIME real (não confiamos no que o navegador disse) ----
$finfo = new finfo(FILEINFO_MIME_TYPE);
$realMime = $finfo->file($file['tmp_name']);
if (!isset(ALLOWED_MIMES[$realMime])) {
    Response::badRequest('Formato não aceito. Envie JPG, PNG ou PDF.');
}

// ---- Gera nome único ----
$extension = ALLOWED_MIMES[$realMime];
$token = bin2hex(random_bytes(16)); // 32 chars hex
$storedName = $token . '.' . $extension;
$destPath   = $tempDir . '/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    Response::serverError('Não foi possível salvar o arquivo. Tente de novo.');
}
@chmod($destPath, 0644);

// ---- Guarda referência na sessão ----
if (!isset($_SESSION['pending_uploads']) || !is_array($_SESSION['pending_uploads'])) {
    $_SESSION['pending_uploads'] = [];
}
$_SESSION['pending_uploads'][$token] = [
    'kind'          => $kind,
    'original_name' => $file['name'],
    'stored_name'   => $storedName,
    'mime_type'     => $realMime,
    'size_bytes'    => (int)$file['size'],
    'created_at'    => time(),
];

Response::success([
    'token'         => $token,
    'kind'          => $kind,
    'original_name' => $file['name'],
    'mime_type'     => $realMime,
    'size_bytes'    => (int)$file['size'],
]);
