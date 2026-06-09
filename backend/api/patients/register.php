<?php
/**
 * POST /api/patients/register.php
 *
 * Endpoint público — fluxo paciente do wizard.
 *
 * Cria em UMA transação:
 *   - users (com senha)
 *   - patients (vinculado ao user, com histórico familiar)
 *   - screenings (com status='submitted')
 *   - screening_answers
 *   - patient_uploads (foto frente + foto perfil + requisição médica)
 *   - socioeconomic_assessments (7 perguntas socioeconômicas)
 *
 * Calcula o score automaticamente e LOGA o usuário (cria sessão PHP).
 *
 * Uploads: o wizard já enviou os arquivos pra /api/uploads/create.php,
 * que os colocou em backend/uploads/_pending/ e guardou tokens na sessão
 * (em $_SESSION['pending_uploads']). Aqui consumimos esses tokens.
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/ScoreCalculator.php';

if (!Request::isMethod('POST')) {
    Response::methodNotAllowed();
}

$body = Request::body();
$patient     = $body['patient']      ?? [];
$guardian    = $body['guardian']     ?? null;
$account     = $body['account']      ?? [];
$answers     = $body['answers']      ?? [];
$uploads     = $body['upload_tokens']?? []; // { photo_front: tok, photo_side: tok, medical_request: tok }
$socio       = $body['socioeconomic']?? [];
// "A triagem é para você?"  true = paciente é o próprio usuário.
// false = quem está preenchendo é responsável e responde por outra pessoa.
$isForSelf   = array_key_exists('is_for_self', $body) ? (bool)$body['is_for_self'] : true;

// Idade do paciente: menores de 18 anos exigem responsável, mesmo que a pessoa
// tenha marcado "é para mim". (Regra de negócio: menor não faz triagem sozinho.)
$isMinor = false;
if (!empty($patient['birth_date'])) {
    try {
        $bd  = new DateTime((string)$patient['birth_date']);
        $age = (new DateTime('today'))->diff($bd)->y;
        $isMinor = $age < 18;
    } catch (Throwable $e) {
        $isMinor = false;
    }
}
// Responsável obrigatório quando: responde por outra pessoa OU paciente é menor.
$requireGuardian = !$isForSelf || $isMinor;

// ===== Validação =====
$v = new Validator(array_merge($patient, $account));
$v->required('full_name')->maxLength('full_name', 180);
$v->required('birth_date')->date('birth_date');
$v->required('biological_sex')->in('biological_sex', ['M', 'F']);
$v->required('email')->email('email')->emailDomain('email');
$v->required('password')->minLength('password', PASSWORD_MIN_LENGTH);
// CPF é opcional, mas se vier deve ser matematicamente válido.
$v->cpf('cpf');

// Telefone do paciente: obrigatório só quando NÃO há responsável.
// Quando há responsável (outra pessoa OU paciente menor), o telefone é o dele.
if (!$requireGuardian) {
    $v->required('phone', 'Telefone do paciente é obrigatório.')
      ->maxLength('phone', 20);
}

// Endereço: CEP, rua, número, bairro, cidade, estado são obrigatórios.
// Complemento é opcional.
$v->required('zip_code', 'CEP é obrigatório.')->maxLength('zip_code', 10);
$v->required('street',   'Rua é obrigatória.')->maxLength('street', 180);
$v->required('number',   'Número é obrigatório.')->maxLength('number', 20);
$v->required('neighborhood', 'Bairro é obrigatório.')->maxLength('neighborhood', 120);
$v->required('city',     'Cidade é obrigatória.')->maxLength('city', 120);
$v->required('state',    'Estado (UF) é obrigatório.');
// UF tem que ter exatamente 2 letras maiúsculas. Como o Validator base
// não tem "regex", checamos manualmente e adicionamos via in() se inválido.
$uf = isset($patient['state']) ? strtoupper(trim((string)$patient['state'])) : '';
if ($uf !== '' && !preg_match('/^[A-Z]{2}$/', $uf)) {
    $v->in('state', ['__INVALID__'], 'Estado (UF) deve ter 2 letras (ex: SP, RJ).');
}

// Exige dados mínimos do responsável (resposta por outra pessoa OU paciente menor).
if ($requireGuardian) {
    if (!is_array($guardian)) $guardian = [];
    $vg = new Validator($guardian);
    $vg->required('name')->maxLength('name', 180);
    $vg->required('relationship')->maxLength('relationship', 40);
    $vg->required('phone')->maxLength('phone', 20);
    if ($vg->fails()) {
        Response::unprocessable('Dados do responsável incompletos.', $vg->errors());
    }
}

if (!is_array($answers) || count($answers) === 0) {
    Response::unprocessable('É preciso responder ao menos um indicador.');
}

// ----- Uploads obrigatórios -----
$pendingFromSession = $_SESSION['pending_uploads'] ?? [];
$requiredKinds = ['photo_front', 'photo_side', 'medical_request'];
$uploadsByKind = [];
$uploadErrors  = [];

foreach ($requiredKinds as $kind) {
    $token = $uploads[$kind] ?? null;
    if (!$token || !isset($pendingFromSession[$token])) {
        $uploadErrors[$kind] = match ($kind) {
            'photo_front'     => 'Falta enviar a foto de frente do paciente.',
            'photo_side'      => 'Falta enviar a foto de perfil do paciente.',
            'medical_request' => 'Falta enviar a requisição médica.',
        };
        continue;
    }
    $info = $pendingFromSession[$token];
    if ($info['kind'] !== $kind) {
        $uploadErrors[$kind] = 'Tipo do arquivo enviado não corresponde ao esperado.';
        continue;
    }
    $uploadsByKind[$kind] = ['token' => $token] + $info;
}

// ----- Socioeconômica obrigatória (1..6); observations opcional -----
$socio = is_array($socio) ? $socio : [];

$validIncomeRanges = ['up_to_1', '1_to_2', '2_to_3', 'above_3'];
$validWorkStatuses = ['formal', 'informal', 'unemployed', 'retired'];
$validEducation    = [
    'fundamental_incomplete', 'fundamental_complete',
    'high_school_incomplete', 'high_school_complete',
    'higher_incomplete',      'higher_complete',
    'postgrad_incomplete',    'postgrad_complete',
];

$socioErrors = [];
$householdSize = (int)($socio['household_size'] ?? 0);
if ($householdSize < 1 || $householdSize > 30) {
    $socioErrors['household_size'] = 'Informe quantas pessoas moram na casa (entre 1 e 30).';
}
if (!in_array($socio['income_range'] ?? '', $validIncomeRanges, true)) {
    $socioErrors['income_range'] = 'Selecione a faixa de renda mensal da família.';
}
$receivesBenefit = !empty($socio['receives_benefit']) ? 1 : 0;
if (!in_array($socio['provider_work_status'] ?? '', $validWorkStatuses, true)) {
    $socioErrors['provider_work_status'] = 'Selecione a situação de trabalho do provedor.';
}
$hasHealthPlan = !empty($socio['has_health_plan']) ? 1 : 0;
if (!in_array($socio['provider_education'] ?? '', $validEducation, true)) {
    $socioErrors['provider_education'] = 'Selecione a escolaridade do responsável/provedor.';
}

// ----- Junta erros e responde de uma vez (mensagens claras) -----
$allErrors = [];
if ($v->fails())             $allErrors = array_merge($allErrors, $v->errors());
if ($uploadErrors)           $allErrors = array_merge($allErrors, $uploadErrors);
if ($socioErrors)            $allErrors = array_merge($allErrors, $socioErrors);

if ($allErrors) {
    Response::unprocessable('Não é possível enviar: faltam informações.', $allErrors);
}

// ===== Persistência =====
$pdo = Database::getConnection();

// E-mail já cadastrado?
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
$stmt->execute([':e' => $account['email']]);
if ($stmt->fetch()) {
    Response::conflict('Já existe uma conta com este e-mail.');
}

// CPF já cadastrado? (CPF é opcional — só checa se foi informado)
// Normalizamos pra "000.000.000-00" antes de gravar e comparar.
$cpfRaw  = isset($patient['cpf']) ? trim((string)$patient['cpf']) : '';
$cpfNorm = null;
if ($cpfRaw !== '') {
    $digits = preg_replace('/\D/', '', $cpfRaw);
    if (strlen($digits) === 11) {
        $cpfNorm = substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.'
                 . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
        $stmt = $pdo->prepare("SELECT id FROM patients WHERE cpf = :c AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':c' => $cpfNorm]);
        if ($stmt->fetch()) {
            Response::conflict('Já existe um paciente cadastrado com este CPF.');
        }
    }
}

// Telefone já cadastrado?
// Decisão de produto: telefone repetido NÃO bloqueia — famílias compartilham
// o mesmo número (irmãos, pai/filho) em triagem de X Frágil. Apenas AVISA:
// se o telefone já existe e a pessoa ainda não confirmou que quer seguir,
// devolvemos um aviso (code PHONE_DUPLICATE) pro front pedir confirmação.
// Quando vier phone_duplicate_ack = true, seguimos normalmente.
$phoneAck     = !empty($body['phone_duplicate_ack']);
$phoneToCheck = !$requireGuardian
    ? ($patient['phone'] ?? '')
    : (is_array($guardian) ? ($guardian['phone'] ?? '') : '');
$phoneDigits  = preg_replace('/\D/', '', (string)$phoneToCheck);
if (!$phoneAck && strlen($phoneDigits) >= 10) {
    // Compara só os dígitos (ignora formatação) tanto no telefone do paciente
    // quanto no do responsável. REGEXP_REPLACE existe a partir do MySQL 8.
    $stmt = $pdo->prepare("
        SELECT id FROM patients
        WHERE deleted_at IS NULL
          AND (
                REGEXP_REPLACE(COALESCE(phone, ''),          '[^0-9]', '') = :d1
             OR REGEXP_REPLACE(COALESCE(guardian_phone, ''), '[^0-9]', '') = :d2
          )
        LIMIT 1
    ");
    $stmt->execute([':d1' => $phoneDigits, ':d2' => $phoneDigits]);
    if ($stmt->fetch()) {
        Response::error('Já existe um cadastro usando este telefone.', 409, 'PHONE_DUPLICATE');
    }
}

$uploadsDir  = UPLOAD_DIR;
$pendingDir  = $uploadsDir . '/_pending';
$movedFiles  = []; // [dst => src] — pra desfazer se a transação falhar

try {
    $pdo->beginTransaction();

    // 1) Cria user
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password_hash, full_name, role)
        VALUES (:e, :p, :n, 'patient')
    ");
    $stmt->execute([
        ':e' => $account['email'],
        ':p' => password_hash($account['password'], PASSWORD_BCRYPT),
        ':n' => $patient['full_name'],
    ]);
    $userId = (int)$pdo->lastInsertId();

    // 2) Cria patient. Guarda o responsável quando ele é obrigatório
    //    (resposta por outra pessoa OU paciente menor de idade).
    $guardianForDb = $requireGuardian ? $guardian : null;

    // Telefone do paciente: só guarda quando NÃO há responsável.
    // Quando há responsável, o telefone de contato fica no responsável.
    $patientPhone = !$requireGuardian && !empty($patient['phone'])
        ? trim((string)$patient['phone'])
        : null;

    // Normaliza CEP: guarda só dígitos no banco pra busca/comparação ficar simples.
    $zipDigits = preg_replace('/\D/', '', (string)($patient['zip_code'] ?? ''));
    $zipCode   = $zipDigits !== '' ? $zipDigits : null;

    $stmt = $pdo->prepare("
        INSERT INTO patients
            (user_id, full_name, birth_date, biological_sex, cpf, phone,
             zip_code, street, number, complement, neighborhood, city, state,
             guardian_name, guardian_relationship, guardian_phone, guardian_email,
             family_history_notes)
        VALUES
            (:uid, :name, :bd, :sex, :cpf, :ph,
             :zip, :str, :num, :cmp, :nb, :ci, :st,
             :gn, :gr, :gp, :ge, :fh)
    ");
    $stmt->execute([
        ':uid' => $userId,
        ':name'=> $patient['full_name'],
        ':bd'  => $patient['birth_date'],
        ':sex' => $patient['biological_sex'],
        ':cpf' => $cpfNorm,
        ':ph'  => $patientPhone,
        ':zip' => $zipCode,
        ':str' => isset($patient['street'])       ? trim((string)$patient['street'])       : null,
        ':num' => isset($patient['number'])       ? trim((string)$patient['number'])       : null,
        ':cmp' => !empty($patient['complement'])  ? trim((string)$patient['complement'])   : null,
        ':nb'  => isset($patient['neighborhood']) ? trim((string)$patient['neighborhood']) : null,
        ':ci'  => isset($patient['city'])         ? trim((string)$patient['city'])         : null,
        ':st'  => isset($patient['state'])        ? strtoupper(trim((string)$patient['state'])) : null,
        ':gn'  => $guardianForDb['name']         ?? null,
        ':gr'  => $guardianForDb['relationship'] ?? null,
        ':gp'  => $guardianForDb['phone']        ?? null,
        ':ge'  => $guardianForDb['email']        ?? null,
        ':fh'  => isset($patient['family_history_notes'])
                    ? (trim($patient['family_history_notes']) !== '' ? trim($patient['family_history_notes']) : null)
                    : null,
    ]);
    $patientId = (int)$pdo->lastInsertId();

    // 3) Calcula score
    $result = ScoreCalculator::calculate($patient['biological_sex'], $answers);

    // 4) Cria screening
    $stmt = $pdo->prepare("
        INSERT INTO screenings
            (patient_id, score, threshold_applied, priority, recommendation,
             status, submitted_at)
        VALUES
            (:pid, :sc, :th, :pr, :rc, 'submitted', NOW())
    ");
    $stmt->execute([
        ':pid' => $patientId,
        ':sc'  => $result['score'],
        ':th'  => $result['threshold'],
        ':pr'  => $result['priority'],
        ':rc'  => $result['recommendation'],
    ]);
    $screeningId = (int)$pdo->lastInsertId();

    // 5) Salva respostas dos indicadores
    $stmt = $pdo->prepare("
        INSERT INTO screening_answers (screening_id, indicator_id, answer, observation)
        VALUES (:sid, :iid, :ans, :obs)
    ");
    foreach ($answers as $a) {
        $stmt->execute([
            ':sid' => $screeningId,
            ':iid' => (int)($a['indicator_id'] ?? 0),
            ':ans' => $a['answer'] ?? 'unknown',
            ':obs' => $a['observation'] ?? null,
        ]);
    }

    // 6) Move uploads pendentes para a pasta do paciente e registra
    $patientFolderRel = 'patients/' . $patientId;
    $patientFolderAbs = $uploadsDir . '/' . $patientFolderRel;
    if (!is_dir($patientFolderAbs)) {
        @mkdir($patientFolderAbs, 0775, true);
    }

    $stmtUp = $pdo->prepare("
        INSERT INTO patient_uploads
            (patient_id, screening_id, kind, original_name, stored_path, mime_type, size_bytes,
             uploaded_by_user_id)
        VALUES
            (:pid, :sid, :kind, :orig, :path, :mime, :size, :uid)
    ");
    foreach ($uploadsByKind as $kind => $up) {
        $src = $pendingDir . '/' . $up['stored_name'];
        if (!is_file($src)) {
            throw new RuntimeException("Arquivo temporário não encontrado: {$kind}");
        }
        $dstName = $up['stored_name']; // já é único (token + extensão)
        $dstAbs  = $patientFolderAbs . '/' . $dstName;
        $dstRel  = $patientFolderRel  . '/' . $dstName;

        if (!@rename($src, $dstAbs)) {
            throw new RuntimeException("Falha ao mover upload {$kind}.");
        }
        $movedFiles[$dstAbs] = $src;

        $stmtUp->execute([
            ':pid'  => $patientId,
            ':sid'  => $screeningId,
            ':kind' => $kind,
            ':orig' => $up['original_name'],
            ':path' => $dstRel,
            ':mime' => $up['mime_type'],
            ':size' => $up['size_bytes'],
            ':uid'  => $userId,
        ]);
    }

    // 7) Salva socioeconômica
    $stmtSocio = $pdo->prepare("
        INSERT INTO socioeconomic_assessments
            (patient_id, screening_id, household_size, income_range,
             receives_benefit, benefit_details,
             provider_work_status, has_health_plan,
             provider_education, observations)
        VALUES
            (:pid, :sid, :hh, :ir, :rb, :bd, :ws, :hp, :ed, :ob)
    ");
    $benefitDetails = $receivesBenefit && !empty($socio['benefit_details'])
        ? trim((string)$socio['benefit_details']) : null;
    $observations = !empty($socio['observations'])
        ? trim((string)$socio['observations']) : null;
    $stmtSocio->execute([
        ':pid' => $patientId,
        ':sid' => $screeningId,
        ':hh'  => $householdSize,
        ':ir'  => $socio['income_range'],
        ':rb'  => $receivesBenefit,
        ':bd'  => $benefitDetails,
        ':ws'  => $socio['provider_work_status'],
        ':hp'  => $hasHealthPlan,
        ':ed'  => $socio['provider_education'],
        ':ob'  => $observations,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    // Devolve arquivos movidos pra pasta temporária pra não deixar lixo
    foreach ($movedFiles as $dst => $src) {
        if (is_file($dst)) @rename($dst, $src);
    }
    throw $e;
}

// 8) Limpa tokens da sessão (já foram processados)
foreach ($uploadsByKind as $kind => $up) {
    unset($_SESSION['pending_uploads'][$up['token']]);
}

// 9) Login automático — cria sessão PHP
Auth::login($userId);

Audit::log('PATIENT_SELF_REGISTERED', 'patient', $patientId, [
    'screening_id' => $screeningId,
    'priority'     => $result['priority'],
]);

Response::created([
    'patient_id'   => $patientId,
    'screening_id' => $screeningId,
    'user' => [
        'id'        => $userId,
        'email'     => $account['email'],
        'full_name' => $patient['full_name'],
        'role'      => 'patient',
    ],
    'message' => 'Recebemos suas informações com sucesso. Nossa equipe analisará os dados e um profissional de saúde entrará em contato.',
]);
