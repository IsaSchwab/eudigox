/**
 * SGX — Lógica do Wizard de Triagem
 *
 * 10 etapas:
 *   1.  Dados do paciente (com pergunta "essa triagem é pra você?")
 *   2.  Dados do responsável (condicional: is_for_self = false)
 *   3.  Criação de acesso (e-mail + senha)
 *   4.  Histórico familiar (texto livre, sem peso no score)
 *   5.  Indicadores de desenvolvimento
 *   6.  Indicadores comportamentais
 *   7.  Indicadores físicos
 *   8.  Uploads (foto frente + foto perfil + requisição médica)
 *   9.  Triagem socioeconômica (7 perguntas)
 *   10. Revisão e envio
 */

const TOTAL_STEPS = 10;

const wizard = {
  currentStep: 1,
  data: {
    patient:  {
      full_name: '', birth_date: '', biological_sex: '', cpf: '',
      phone: '',
      zip_code: '', street: '', number: '', complement: '',
      neighborhood: '', city: '', state: '',
      family_history_notes: '',
    },
    guardian: { name: '', relationship: '', phone: '', email: '' },
    account:  { email: '', password: '', password_confirm: '', terms: false },
    answers:  {}, // { indicatorId: { answer, observation } }
    isForSelf: true, // "essa triagem é pra você?" (sim por padrão)
    // Tokens devolvidos pelo backend após o upload. Não guardamos o File aqui
    // (referência ao DOM), só o que o backend precisa pra promover o arquivo.
    uploads: {
      photo_front:     null, // { token, original_name, mime_type, size_bytes }
      photo_side:      null,
      medical_request: null,
    },
    socioeconomic: {
      household_size:       '',
      income_range:         '',
      receives_benefit:     false,
      benefit_details:      '',
      provider_work_status: '',
      has_health_plan:      false,
      provider_education:   '',
      observations:         '',
    },
  },
  indicators: { development: [], behavioral: [], physical: [] },
  hasGuardianStep: false, // espelha isForSelf=false; mantido pelo nome usado em vários pontos
};

/* ===== Carregamento dos indicadores ===== */
async function loadIndicators(sex = null) {
  const path = sex ? `/indicators/list?sex=${sex}` : `/indicators/list`;
  try {
    const data = await api.get(path);
    wizard.indicators = data.indicators_by_category;
  } catch (e) {
    toast.error('Não foi possível carregar os indicadores. Tente recarregar a página.');
    console.error(e);
  }
}

/* ===== Cálculo de idade (mantido — pode ser útil em outras telas) ===== */
function calculateAge(birthDate) {
  if (!birthDate) return null;
  const today = new Date();
  const birth = new Date(birthDate);
  let age = today.getFullYear() - birth.getFullYear();
  const m = today.getMonth() - birth.getMonth();
  if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
  return age;
}

/* ===== Validação de CPF (algoritmo dos dígitos verificadores) =====
 * Aceita o CPF com ou sem pontuação. NÃO confere com a Receita Federal —
 * só verifica que a estrutura matemática está correta. Pega quase todos
 * os erros de digitação e CPFs claramente fictícios (000.000.000-00 etc.). */
function isValidCPF(value) {
  if (!value) return false;
  const d = String(value).replace(/\D/g, '');
  if (d.length !== 11) return false;
  if (/^(\d)\1{10}$/.test(d)) return false;

  let sum = 0;
  for (let i = 0; i < 9; i++) sum += parseInt(d[i], 10) * (10 - i);
  let d1 = (10 * sum) % 11;
  if (d1 === 10) d1 = 0;
  if (d1 !== parseInt(d[9], 10)) return false;

  sum = 0;
  for (let i = 0; i < 10; i++) sum += parseInt(d[i], 10) * (11 - i);
  let d2 = (10 * sum) % 11;
  if (d2 === 10) d2 = 0;
  if (d2 !== parseInt(d[10], 10)) return false;

  return true;
}

/* Formata CPF em "000.000.000-00" enquanto a pessoa digita. */
function formatCPF(value) {
  const d = String(value || '').replace(/\D/g, '').slice(0, 11);
  if (d.length <= 3)  return d;
  if (d.length <= 6)  return d.slice(0, 3) + '.' + d.slice(3);
  if (d.length <= 9)  return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6);
  return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6, 9) + '-' + d.slice(9);
}

/* Formata CEP em "00000-000" enquanto a pessoa digita. */
function formatCEP(value) {
  const d = String(value || '').replace(/\D/g, '').slice(0, 8);
  if (d.length <= 5) return d;
  return d.slice(0, 5) + '-' + d.slice(5);
}

/* Formata telefone como "(00) 00000-0000" (celular) ou "(00) 0000-0000" (fixo). */
function formatPhone(value) {
  const d = String(value || '').replace(/\D/g, '').slice(0, 11);
  if (d.length === 0)  return '';
  if (d.length <= 2)   return '(' + d;
  if (d.length <= 6)   return '(' + d.slice(0, 2) + ') ' + d.slice(2);
  if (d.length <= 10)  return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
  return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
}

/**
 * Busca um CEP na ViaCEP e preenche os campos de endereço.
 * Se o CEP não for encontrado, deixa um aviso e libera os campos pra
 * preenchimento manual (opção A do planejamento).
 */
async function lookupCep(digits, statusEl) {
  if (statusEl) {
    statusEl.style.color = 'var(--color-text-muted)';
    statusEl.textContent = 'Buscando endereço...';
  }
  try {
    const res = await fetch(`https://viacep.com.br/ws/${digits}/json/`);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    if (data.erro) {
      if (statusEl) {
        statusEl.style.color = 'var(--color-danger, #c5392f)';
        statusEl.textContent = 'CEP não encontrado. Preencha o endereço manualmente.';
      }
      return;
    }

    // ViaCEP devolve: logradouro, bairro, localidade, uf, complemento
    const p = wizard.data.patient;
    p.street       = data.logradouro || p.street || '';
    p.neighborhood = data.bairro     || p.neighborhood || '';
    p.city         = data.localidade || p.city || '';
    p.state        = (data.uf || '').toUpperCase();

    // Atualiza os inputs (sem re-render completo, pra não perder o foco do CEP)
    const setVal = (id, val) => { const el = $('#' + id); if (el) el.value = val; };
    setVal('street',       p.street);
    setVal('neighborhood', p.neighborhood);
    setVal('city',         p.city);
    setVal('state',        p.state);

    if (statusEl) {
      statusEl.style.color = 'var(--color-success, #2a7a3e)';
      statusEl.textContent = '✓ Endereço encontrado. Confira o número e o complemento.';
    }
  } catch (err) {
    if (statusEl) {
      statusEl.style.color = 'var(--color-danger, #c5392f)';
      statusEl.textContent = 'Não foi possível consultar o CEP. Preencha manualmente.';
    }
  }
}

/* ===== Validação de cada etapa ===== */
function validateStep(step) {
  const errors = {};
  if (step === 1) {
    const p = wizard.data.patient;
    if (!p.full_name.trim())              errors.full_name      = 'Nome completo é obrigatório.';
    if (!p.birth_date)                    errors.birth_date     = 'Data de nascimento é obrigatória.';
    if (!['M', 'F'].includes(p.biological_sex))
                                          errors.biological_sex = 'Selecione o sexo biológico.';
    // CPF é opcional. Mas se foi preenchido, precisa ser matematicamente válido.
    if (p.cpf && p.cpf.trim() && !isValidCPF(p.cpf)) {
      errors.cpf = 'CPF inválido. Confira os números digitados.';
    }
    // Telefone do paciente: obrigatório só quando "é pra você".
    // Quando outra pessoa responde, quem precisa de telefone é o responsável (etapa 2).
    if (wizard.data.isForSelf && !p.phone.trim()) {
      errors.phone = 'Telefone do paciente é obrigatório.';
    }
    // Endereço (obrigatório, exceto complemento)
    const cepDigits = (p.zip_code || '').replace(/\D/g, '');
    if (cepDigits.length !== 8) {
      errors.zip_code = 'CEP é obrigatório (8 dígitos).';
    }
    if (!p.street.trim())       errors.street       = 'Rua é obrigatória.';
    if (!p.number.trim())       errors.number       = 'Número é obrigatório.';
    if (!p.neighborhood.trim()) errors.neighborhood = 'Bairro é obrigatório.';
    if (!p.city.trim())         errors.city         = 'Cidade é obrigatória.';
    if (!/^[A-Z]{2}$/.test((p.state || '').toUpperCase()))
                                errors.state        = 'Estado (UF) é obrigatório (ex: SP, RJ).';
  }
  if (step === 2 && wizard.hasGuardianStep) {
    const g = wizard.data.guardian;
    if (!g.name.trim())                   errors.guardian_name  = 'Nome do responsável é obrigatório.';
    if (!g.relationship.trim())           errors.guardian_relationship = 'Grau de parentesco é obrigatório.';
    if (!g.phone.trim())                  errors.guardian_phone = 'Telefone é obrigatório.';
  }
  if (step === 3) {
    const a = wizard.data.account;
    if (!a.email.trim() || !/.+@.+\..+/.test(a.email))
                                          errors.email          = 'E-mail inválido.';
    if (!a.password || a.password.length < 8)
                                          errors.password       = 'Senha deve ter no mínimo 8 caracteres.';
    if (a.password !== a.password_confirm)
                                          errors.password_confirm = 'As senhas não coincidem.';
    if (!a.terms)                         errors.terms          = 'É preciso aceitar os termos.';
  }
  // step === 4 (histórico familiar): textarea opcional, sem validação.
  if ([5, 6, 7].includes(step)) {
    const cat = step === 5 ? 'development' : (step === 6 ? 'behavioral' : 'physical');
    const list = wizard.indicators[cat] || [];
    const unanswered = list.filter(i => !wizard.data.answers[i.id]);
    if (unanswered.length > 0) {
      errors.indicators = `Responda todos os ${list.length} itens desta etapa.`;
    }
  }
  if (step === 8) {
    const u = wizard.data.uploads;
    if (!u.photo_front)     errors.photo_front     = 'Envie a foto de frente do paciente.';
    if (!u.photo_side)      errors.photo_side      = 'Envie a foto de perfil do paciente.';
    if (!u.medical_request) errors.medical_request = 'Envie a requisição médica.';
  }
  if (step === 9) {
    const s = wizard.data.socioeconomic;
    const validIncome   = ['up_to_1', '1_to_2', '2_to_3', 'above_3'];
    const validWork     = ['formal', 'informal', 'unemployed', 'retired'];
    const validEduc     = [
      'fundamental_incomplete', 'fundamental_complete',
      'high_school_incomplete', 'high_school_complete',
      'higher_incomplete',      'higher_complete',
      'postgrad_incomplete',    'postgrad_complete',
    ];
    const hh = parseInt(s.household_size, 10);
    if (!Number.isFinite(hh) || hh < 1 || hh > 30)
      errors.household_size = 'Informe quantas pessoas moram na casa (1 a 30).';
    if (!validIncome.includes(s.income_range))
      errors.income_range = 'Selecione a faixa de renda da família.';
    if (!validWork.includes(s.provider_work_status))
      errors.provider_work_status = 'Selecione a situação de trabalho do provedor.';
    if (!validEduc.includes(s.provider_education))
      errors.provider_education = 'Selecione a escolaridade do responsável/provedor.';
    // receives_benefit, has_health_plan, observations e benefit_details: opcionais.
  }
  return errors;
}

/* ===== Renderização das etapas ===== */
function renderStep() {
  const root = $('#wizard-body');
  const step = wizard.currentStep;
  let html = '';

  if (step === 1)  html = renderStep1();
  if (step === 2)  html = renderStep2();
  if (step === 3)  html = renderStep3();
  if (step === 4)  html = renderStepFamilyHistory();
  if (step === 5)  html = renderIndicatorStep('development', 'Desenvolvimento', 'Como a criança ou adulto se desenvolveu na fala, atenção e aprendizado.');
  if (step === 6)  html = renderIndicatorStep('behavioral', 'Comportamento', 'Como ele(a) se comporta em situações sociais e no dia a dia.');
  if (step === 7)  html = renderIndicatorStep('physical',   'Características físicas', 'Aspectos físicos observáveis.');
  if (step === 8)  html = renderStepUploads();
  if (step === 9)  html = renderStepSocioeconomic();
  if (step === 10) html = renderReview();

  root.innerHTML = html;
  updateHeader();
  updateProgress();
  updateNavButtons();
  bindStepEvents();
}

function updateHeader() {
  const titles = {
    1:  ['Etapa 1 de 10',  'Dados do paciente',                'Vamos começar com algumas informações básicas.'],
    2:  ['Etapa 2 de 10',  'Dados do responsável',             'Como a triagem não é para você, precisamos dos dados de quem está respondendo.'],
    3:  ['Etapa 3 de 10',  'Criação de acesso',                'Crie uma senha para acompanhar a triagem depois.'],
    4:  ['Etapa 4 de 10',  'Histórico familiar',               'Conte se há casos relevantes na família. Esse relato vai pro prontuário e não afeta o score.'],
    5:  ['Etapa 5 de 10',  'Indicadores de desenvolvimento',   'Responda Sim, Não ou Não sei para cada item.'],
    6:  ['Etapa 6 de 10',  'Indicadores de comportamento',     'Continue respondendo abaixo.'],
    7:  ['Etapa 7 de 10',  'Características físicas',          'Mais alguns itens e seguimos para os anexos.'],
    8:  ['Etapa 8 de 10',  'Fotos e requisição médica',        'Envie duas fotos do rosto (frente e perfil) e a requisição médica.'],
    9:  ['Etapa 9 de 10',  'Triagem socioeconômica',           'Algumas perguntas sobre a família. Servem para a clínica entender o contexto.'],
    10: ['Etapa 10 de 10', 'Revisão e envio',                  'Confira tudo antes de enviar.'],
  };
  const [step, title, sub] = titles[wizard.currentStep];
  $('#wizard-step-indicator').textContent = step;
  $('#wizard-title').textContent = title;
  $('#wizard-subtitle').textContent = sub;
}

function updateProgress() {
  const bars = $$('.wizard-progress-bar');
  bars.forEach((bar, idx) => {
    bar.classList.remove('completed', 'active');
    if (idx + 1 < wizard.currentStep) bar.classList.add('completed');
    if (idx + 1 === wizard.currentStep) bar.classList.add('active');
  });
}

function updateNavButtons() {
  const back = $('#btn-back');
  const next = $('#btn-next');
  back.style.visibility = wizard.currentStep === 1 ? 'hidden' : 'visible';
  next.textContent = wizard.currentStep === TOTAL_STEPS ? 'Enviar para análise' : 'Continuar';
}

/* ===== Templates de cada etapa ===== */
function renderStep1() {
  const p = wizard.data.patient;
  const self = wizard.data.isForSelf;
  return `
    <div class="field">
      <label>Essa triagem é para você?</label>
      <div class="radio-group" style="grid-template-columns: 1fr 1fr;">
        <label class="radio-option ${self === true ? 'selected' : ''}">
          <input type="radio" name="is_for_self" value="yes" ${self === true ? 'checked' : ''}> Sim, é para mim
        </label>
        <label class="radio-option ${self === false ? 'selected' : ''}">
          <input type="radio" name="is_for_self" value="no" ${self === false ? 'checked' : ''}> Não, estou respondendo por outra pessoa
        </label>
      </div>
      <span class="help">${ self === false
        ? 'No próximo passo vamos pedir seus dados como responsável.'
        : 'Preencha os dados pessoais abaixo.' }</span>
    </div>

    <div class="field">
      <label for="full_name">Nome completo ${ self === false ? 'do paciente' : '' }</label>
      <input class="input" id="full_name" type="text" value="${escapeAttr(p.full_name)}" placeholder="Como aparece no documento" />
    </div>
    <div class="grid gap-4" style="grid-template-columns: 1fr 1fr;">
      <div class="field">
        <label for="birth_date">Data de nascimento</label>
        <input class="input" id="birth_date" type="date" value="${p.birth_date}" />
      </div>
      <div class="field">
        <label>Sexo biológico</label>
        <div class="radio-group" style="grid-template-columns: 1fr 1fr;">
          <label class="radio-option ${p.biological_sex === 'M' ? 'selected' : ''}">
            <input type="radio" name="biological_sex" value="M" ${p.biological_sex === 'M' ? 'checked' : ''}> Masculino
          </label>
          <label class="radio-option ${p.biological_sex === 'F' ? 'selected' : ''}">
            <input type="radio" name="biological_sex" value="F" ${p.biological_sex === 'F' ? 'checked' : ''}> Feminino
          </label>
        </div>
        <span class="help">Essencial para o cálculo da triagem.</span>
      </div>
    </div>
    <div class="field">
      <label for="cpf">CPF (opcional)</label>
      <input class="input" id="cpf" type="text" value="${escapeAttr(p.cpf)}" placeholder="000.000.000-00" />
    </div>

    ${ self ? `
      <div class="field">
        <label for="phone">Telefone do paciente</label>
        <input class="input" id="phone" type="tel" value="${escapeAttr(p.phone)}" placeholder="(00) 00000-0000" />
        <span class="help">Pode ser celular ou fixo, com DDD.</span>
      </div>
    ` : `
      <p class="text-small text-muted" style="margin: var(--space-3) 0;">
        Como você está respondendo por outra pessoa, o telefone de contato será o seu (responsável), pedido no próximo passo.
      </p>
    `}

    <div style="border-top: 1px solid var(--color-border); margin-top: var(--space-5); padding-top: var(--space-4);">
      <h3 style="font-family: var(--font-body); font-size: var(--fs-base); margin-bottom: var(--space-3);">
        Endereço ${ self === false ? 'do paciente' : '' }
      </h3>

      <div class="grid gap-4" style="grid-template-columns: 1fr 2fr;">
        <div class="field">
          <label for="zip_code">CEP</label>
          <input class="input" id="zip_code" type="text" value="${escapeAttr(p.zip_code)}"
                 placeholder="00000-000" maxlength="9" inputmode="numeric" />
          <span class="help" id="cep_status" style="min-height: 1.2em; display: block;"></span>
        </div>
        <div class="field">
          <label for="street">Rua</label>
          <input class="input" id="street" type="text" value="${escapeAttr(p.street)}" placeholder="Avenida das Flores" />
        </div>
      </div>

      <div class="grid gap-4" style="grid-template-columns: 1fr 2fr;">
        <div class="field">
          <label for="number">Número</label>
          <input class="input" id="number" type="text" value="${escapeAttr(p.number)}" placeholder="123" />
        </div>
        <div class="field">
          <label for="complement">Complemento (opcional)</label>
          <input class="input" id="complement" type="text" value="${escapeAttr(p.complement)}" placeholder="Apto 42, bloco B..." />
        </div>
      </div>

      <div class="field">
        <label for="neighborhood">Bairro</label>
        <input class="input" id="neighborhood" type="text" value="${escapeAttr(p.neighborhood)}" placeholder="Centro" />
      </div>

      <div class="grid gap-4" style="grid-template-columns: 2fr 1fr;">
        <div class="field">
          <label for="city">Cidade</label>
          <input class="input" id="city" type="text" value="${escapeAttr(p.city)}" placeholder="São Paulo" />
        </div>
        <div class="field">
          <label for="state">UF</label>
          <input class="input" id="state" type="text" value="${escapeAttr(p.state)}" placeholder="SP" maxlength="2" style="text-transform: uppercase;" />
        </div>
      </div>
    </div>
  `;
}

function renderStep2() {
  const g = wizard.data.guardian;
  return `
    <div class="field">
      <label for="guardian_name">Nome completo do responsável</label>
      <input class="input" id="guardian_name" type="text" value="${escapeAttr(g.name)}" />
    </div>
    <div class="grid gap-4" style="grid-template-columns: 1fr 1fr;">
      <div class="field">
        <label for="guardian_relationship">Parentesco</label>
        <select class="select" id="guardian_relationship">
          <option value="">Selecione...</option>
          ${['Pai', 'Mãe', 'Avô/Avó', 'Tio(a)', 'Tutor(a) legal', 'Outro']
            .map(o => `<option ${g.relationship === o ? 'selected' : ''}>${o}</option>`).join('')}
        </select>
      </div>
      <div class="field">
        <label for="guardian_phone">Telefone</label>
        <input class="input" id="guardian_phone" type="tel" value="${escapeAttr(g.phone)}" placeholder="(00) 00000-0000" />
      </div>
    </div>
    <div class="field">
      <label for="guardian_email">E-mail (opcional)</label>
      <input class="input" id="guardian_email" type="email" value="${escapeAttr(g.email)}" />
    </div>
  `;
}

function renderStep3() {
  const a = wizard.data.account;
  return `
    <div class="field">
      <label for="account_email">E-mail de acesso</label>
      <input class="input" id="account_email" type="email" value="${escapeAttr(a.email)}" placeholder="seu@email.com" autocomplete="email" />
      <span class="help">Você usará este e-mail para entrar e ver os resultados.</span>
    </div>
    <div class="grid gap-4" style="grid-template-columns: 1fr 1fr;">
      <div class="field">
        <label for="password">Senha</label>
        <input class="input" id="password" type="password" value="${escapeAttr(a.password)}" placeholder="Mínimo 8 caracteres" autocomplete="new-password" />
      </div>
      <div class="field">
        <label for="password_confirm">Confirmar senha</label>
        <input class="input" id="password_confirm" type="password" value="${escapeAttr(a.password_confirm)}" autocomplete="new-password" />
      </div>
    </div>
    <div class="field" style="flex-direction: row; align-items: flex-start; gap: var(--space-3);">
      <input type="checkbox" id="terms" ${a.terms ? 'checked' : ''} style="margin-top: 4px;" />
      <label for="terms" style="font-weight: 400; font-size: var(--fs-sm);">
        Aceito os <a href="#" target="_blank">Termos de Uso</a> e a <a href="#" target="_blank">Política de Privacidade</a> do SGX, conforme a LGPD.
      </label>
    </div>
  `;
}

function renderStepFamilyHistory() {
  const p = wizard.data.patient;
  return `
    <div class="field">
      <label for="family_history_notes">Há histórico familiar relevante?</label>
      <textarea
        class="input"
        id="family_history_notes"
        rows="6"
        style="resize: vertical; min-height: 140px; font-family: inherit;"
        placeholder="Descreva, se houver, casos na família de deficiência intelectual, atraso no desenvolvimento, dificuldades de aprendizagem, autismo, menopausa precoce, abortos recorrentes, ataxia ou tremores. Se não houver ou você não souber, pode deixar em branco."
      >${escapeHtml(p.family_history_notes || '')}</textarea>
      <span class="help">Esse relato é opcional e serve para a equipe clínica conhecer o contexto. <strong>Não entra no cálculo do score.</strong></span>
    </div>
  `;
}

function renderIndicatorStep(category, title, sub) {
  const list = wizard.indicators[category] || [];
  if (list.length === 0) return `<p class="text-muted">Carregando...</p>`;

  return list.map(ind => {
    const ans = wizard.data.answers[ind.id]?.answer;
    return `
      <div class="indicator-card ${ans ? 'answered' : ''}" data-indicator-id="${ind.id}">
        <div class="indicator-question">
          <div class="indicator-text">
            <strong>${escapeHtml(ind.lay_label)}</strong>
            <small>Termo clínico: ${escapeHtml(ind.display_name)}</small>
          </div>
          <button type="button" class="tooltip-trigger" aria-label="Mais informações">
            ?
            <span class="tooltip-content">${escapeHtml(ind.clinical_tooltip)}</span>
          </button>
        </div>
        <div class="radio-group">
          ${['yes', 'no', 'unknown'].map(opt => {
            const labelMap = { yes: 'Sim', no: 'Não', unknown: 'Não sei' };
            return `
              <label class="radio-option ${ans === opt ? 'selected' : ''}">
                <input type="radio" name="ind_${ind.id}" value="${opt}" ${ans === opt ? 'checked' : ''}>
                ${labelMap[opt]}
              </label>
            `;
          }).join('')}
        </div>
      </div>
    `;
  }).join('');
}

/* ===== Etapa 8 — Uploads (fotos + requisição médica) ===== */
function renderStepUploads() {
  const u = wizard.data.uploads;
  return `
    <div class="upload-section" style="background: var(--color-bg-soft, #f5f7fa); border-radius: 8px; padding: var(--space-4); margin-bottom: var(--space-5);">
      <h3 style="margin-top: 0; font-family: var(--font-body); font-size: var(--fs-base);">Foto do rosto — frente e perfil</h3>
      <p class="text-small" style="margin-top: var(--space-1);">
        Precisamos de <strong>duas fotos</strong> do rosto do paciente para que a equipe clínica observe características faciais associadas à síndrome.
      </p>
      <ul class="text-small text-muted" style="margin-top: var(--space-2); padding-left: var(--space-4); line-height: 1.6;">
        <li>Em frente a uma <strong>parede branca ou clara</strong>, sem objetos atrás</li>
        <li>Boa <strong>iluminação</strong>, sem sombra forte no rosto</li>
        <li>Rosto <strong>centralizado</strong> e olhando direto para a câmera (foto de frente)</li>
        <li>Mesmo enquadramento com o rosto <strong>virado 90° para o lado</strong> (foto de perfil)</li>
        <li>Sem óculos, boné ou cabelo cobrindo o rosto</li>
        <li>Foto nítida, sem filtros</li>
      </ul>
    </div>

    ${renderUploadField('photo_front', 'Foto de frente', 'image/jpeg,image/png', u.photo_front)}
    ${renderUploadField('photo_side',  'Foto de perfil', 'image/jpeg,image/png', u.photo_side)}

    <div class="upload-section" style="background: var(--color-bg-soft, #f5f7fa); border-radius: 8px; padding: var(--space-4); margin: var(--space-6) 0 var(--space-5);">
      <h3 style="margin-top: 0; font-family: var(--font-body); font-size: var(--fs-base);">Requisição médica</h3>
      <p class="text-small" style="margin-top: var(--space-1);">
        A requisição é um <strong>pedido de exame assinado por um médico</strong>, solicitando a investigação molecular da Síndrome do X Frágil (geralmente PCR / Southern Blotting do gene FMR1).
      </p>
      <p class="text-small" style="margin-top: var(--space-2);">
        Esse documento é <strong>obrigatório</strong> para realizar o exame molecular caso a triagem indique necessidade. Se ainda não tem, peça ao médico que acompanha o paciente (pediatra, geneticista, neurologista, clínico geral).
      </p>
      <p class="text-small text-muted" style="margin-top: var(--space-2);">
        Aceitamos <strong>foto ou PDF</strong>. Garanta que esteja legível e que o <strong>carimbo/CRM e a assinatura</strong> do médico apareçam claramente.
      </p>
    </div>

    ${renderUploadField('medical_request', 'Requisição médica', 'application/pdf,image/jpeg,image/png', u.medical_request)}

    <p class="text-small text-muted" style="margin-top: var(--space-4);">
      🔒 Tamanho máximo: 5 MB por arquivo. Esses dados são sensíveis (LGPD) e ficam protegidos no servidor.
    </p>
  `;
}

function renderUploadField(kind, label, accept, current) {
  const id = `upload_${kind}`;
  const sizeKb = current ? Math.round(current.size_bytes / 1024) : 0;
  return `
    <div class="field" data-upload-kind="${kind}">
      <label for="${id}">${label}</label>
      <input
        class="input"
        id="${id}"
        type="file"
        accept="${accept}"
        ${current ? 'data-has-file="1"' : ''}
        style="padding: var(--space-2);"
      />
      <div class="upload-status" data-upload-status="${kind}" style="margin-top: var(--space-2); font-size: var(--fs-sm);">
        ${ current
          ? `<span style="color: var(--color-success, #2a7a3e);">✓ Enviado: ${escapeHtml(current.original_name)} (${sizeKb} KB)</span>`
          : `<span class="text-muted">Nenhum arquivo enviado ainda.</span>` }
      </div>
    </div>
  `;
}

/* ===== Etapa 9 — Triagem socioeconômica ===== */
function renderStepSocioeconomic() {
  const s = wizard.data.socioeconomic;
  return `
    <div class="field">
      <label for="socio_household_size">1. Quantas pessoas moram na casa (incluindo o paciente)?</label>
      <input class="input" id="socio_household_size" type="number" min="1" max="30" step="1"
             value="${escapeAttr(s.household_size)}" placeholder="Ex: 4" />
    </div>

    <div class="field">
      <label>2. Renda mensal total da família</label>
      <div class="radio-group" style="grid-template-columns: 1fr 1fr;">
        ${[
          ['up_to_1', 'Até 1 salário mínimo'],
          ['1_to_2',  'De 1 a 2 salários'],
          ['2_to_3',  'De 2 a 3 salários'],
          ['above_3', 'Mais de 3 salários'],
        ].map(([v, lbl]) => `
          <label class="radio-option ${s.income_range === v ? 'selected' : ''}">
            <input type="radio" name="socio_income_range" value="${v}" ${s.income_range === v ? 'checked' : ''}>
            ${lbl}
          </label>
        `).join('')}
      </div>
    </div>

    <div class="field">
      <label>3. A família recebe algum benefício social?</label>
      <div class="radio-group" style="grid-template-columns: 1fr 1fr;">
        <label class="radio-option ${s.receives_benefit === true ? 'selected' : ''}">
          <input type="radio" name="socio_receives_benefit" value="yes" ${s.receives_benefit === true ? 'checked' : ''}> Sim
        </label>
        <label class="radio-option ${s.receives_benefit === false ? 'selected' : ''}">
          <input type="radio" name="socio_receives_benefit" value="no" ${s.receives_benefit === false ? 'checked' : ''}> Não
        </label>
      </div>
      ${s.receives_benefit ? `
        <input class="input" id="socio_benefit_details" type="text"
               value="${escapeAttr(s.benefit_details)}"
               placeholder="Quais benefícios? Ex: Bolsa Família, BPC..."
               style="margin-top: var(--space-2);" />
      ` : ''}
    </div>

    <div class="field">
      <label>4. Situação de trabalho do principal provedor</label>
      <div class="radio-group" style="grid-template-columns: 1fr 1fr;">
        ${[
          ['formal',     'Emprego formal (CLT, servidor)'],
          ['informal',   'Trabalho informal / autônomo'],
          ['unemployed', 'Desempregado(a)'],
          ['retired',    'Aposentado(a) / pensionista'],
        ].map(([v, lbl]) => `
          <label class="radio-option ${s.provider_work_status === v ? 'selected' : ''}">
            <input type="radio" name="socio_work_status" value="${v}" ${s.provider_work_status === v ? 'checked' : ''}>
            ${lbl}
          </label>
        `).join('')}
      </div>
    </div>

    <div class="field">
      <label>5. A família possui plano de saúde?</label>
      <div class="radio-group" style="grid-template-columns: 1fr 1fr;">
        <label class="radio-option ${s.has_health_plan === true ? 'selected' : ''}">
          <input type="radio" name="socio_has_plan" value="yes" ${s.has_health_plan === true ? 'checked' : ''}> Sim
        </label>
        <label class="radio-option ${s.has_health_plan === false ? 'selected' : ''}">
          <input type="radio" name="socio_has_plan" value="no" ${s.has_health_plan === false ? 'checked' : ''}> Não
        </label>
      </div>
    </div>

    <div class="field">
      <label for="socio_education">6. Escolaridade do responsável / provedor</label>
      <select class="select" id="socio_education">
        <option value="">Selecione...</option>
        ${[
          ['fundamental_incomplete', 'Ensino fundamental incompleto'],
          ['fundamental_complete',   'Ensino fundamental completo'],
          ['high_school_incomplete', 'Ensino médio incompleto'],
          ['high_school_complete',   'Ensino médio completo'],
          ['higher_incomplete',      'Ensino superior incompleto'],
          ['higher_complete',        'Ensino superior completo'],
          ['postgrad_incomplete',    'Pós-graduação incompleta'],
          ['postgrad_complete',      'Pós-graduação completa'],
        ].map(([v, lbl]) => `
          <option value="${v}" ${s.provider_education === v ? 'selected' : ''}>${lbl}</option>
        `).join('')}
      </select>
    </div>

    <div class="field">
      <label for="socio_observations">7. Observações adicionais (opcional)</label>
      <textarea class="input" id="socio_observations" rows="4"
                style="resize: vertical; min-height: 90px; font-family: inherit;"
                placeholder="Algo mais que ache importante para a clínica saber...">${escapeHtml(s.observations || '')}</textarea>
    </div>

    <p class="text-small text-muted" style="margin-top: var(--space-4);">
      Estas informações ajudam a equipe clínica a conhecer o contexto da família.
      A decisão sobre apoio é sempre tomada por um profissional — o sistema não decide nada automaticamente.
    </p>
  `;
}

function renderReview() {
  const p = wizard.data.patient;
  const g = wizard.data.guardian;
  const a = wizard.data.account;
  const allInd = [...wizard.indicators.development, ...wizard.indicators.behavioral, ...wizard.indicators.physical];
  const yesCount = allInd.filter(i => wizard.data.answers[i.id]?.answer === 'yes').length;

  return `
    <div class="review-section">
      <h4>Paciente</h4>
      <ul class="review-list">
        <li><span class="label">Triagem para</span><span class="value">${wizard.data.isForSelf ? 'Mim mesmo(a)' : 'Outra pessoa (sou responsável)'}</span></li>
        <li><span class="label">Nome</span><span class="value">${escapeHtml(p.full_name)}</span></li>
        <li><span class="label">Nascimento</span><span class="value">${formatDate(p.birth_date)}</span></li>
        <li><span class="label">Sexo</span><span class="value">${p.biological_sex === 'M' ? 'Masculino' : 'Feminino'}</span></li>
        ${p.cpf ? `<li><span class="label">CPF</span><span class="value">${escapeHtml(p.cpf)}</span></li>` : ''}
        ${wizard.data.isForSelf && p.phone ? `<li><span class="label">Telefone</span><span class="value">${escapeHtml(p.phone)}</span></li>` : ''}
      </ul>
    </div>

    <div class="review-section">
      <h4>Endereço</h4>
      <ul class="review-list">
        <li><span class="label">CEP</span><span class="value">${escapeHtml(p.zip_code || '—')}</span></li>
        <li><span class="label">Rua</span><span class="value">${escapeHtml(p.street || '—')}, ${escapeHtml(p.number || '—')}${p.complement ? ' — ' + escapeHtml(p.complement) : ''}</span></li>
        <li><span class="label">Bairro</span><span class="value">${escapeHtml(p.neighborhood || '—')}</span></li>
        <li><span class="label">Cidade / UF</span><span class="value">${escapeHtml(p.city || '—')} / ${escapeHtml(p.state || '—')}</span></li>
      </ul>
    </div>

    ${wizard.hasGuardianStep ? `
    <div class="review-section">
      <h4>Responsável</h4>
      <ul class="review-list">
        <li><span class="label">Nome</span><span class="value">${escapeHtml(g.name)}</span></li>
        <li><span class="label">Parentesco</span><span class="value">${escapeHtml(g.relationship)}</span></li>
        <li><span class="label">Telefone</span><span class="value">${escapeHtml(g.phone)}</span></li>
      </ul>
    </div>` : ''}

    <div class="review-section">
      <h4>Acesso</h4>
      <ul class="review-list">
        <li><span class="label">E-mail</span><span class="value">${escapeHtml(a.email)}</span></li>
      </ul>
    </div>

    <div class="review-section">
      <h4>Histórico familiar</h4>
      ${ p.family_history_notes && p.family_history_notes.trim()
        ? `<p style="white-space: pre-wrap; font-size: var(--fs-sm); line-height: 1.55;">${escapeHtml(p.family_history_notes)}</p>`
        : `<p class="text-small text-muted">Nenhum relato informado.</p>` }
    </div>

    <div class="review-section">
      <h4>Indicadores respondidos</h4>
      <ul class="review-list">
        <li><span class="label">Total de itens</span><span class="value">${allInd.length}</span></li>
        <li><span class="label">Respondidos "Sim"</span><span class="value">${yesCount}</span></li>
      </ul>
    </div>

    <div class="review-section">
      <h4>Arquivos enviados</h4>
      <ul class="review-list">
        <li><span class="label">Foto de frente</span><span class="value">${renderUploadCheck(wizard.data.uploads.photo_front)}</span></li>
        <li><span class="label">Foto de perfil</span><span class="value">${renderUploadCheck(wizard.data.uploads.photo_side)}</span></li>
        <li><span class="label">Requisição médica</span><span class="value">${renderUploadCheck(wizard.data.uploads.medical_request)}</span></li>
      </ul>
    </div>

    <div class="review-section">
      <h4>Triagem socioeconômica</h4>
      ${renderSocioReview(wizard.data.socioeconomic)}
    </div>

    <p class="text-small text-muted" style="margin-top: var(--space-4);">
      Ao enviar, sua conta será criada e os dados encaminhados à equipe clínica.
    </p>
  `;
}

function renderUploadCheck(u) {
  if (!u) return `<span style="color: var(--color-danger, #c5392f);">✗ Faltando</span>`;
  return `<span style="color: var(--color-success, #2a7a3e);">✓ ${escapeHtml(u.original_name)}</span>`;
}

function renderSocioReview(s) {
  if (!s.household_size) {
    return `<p class="text-small text-muted">Não preenchida.</p>`;
  }
  const incomeLabels = {
    up_to_1: 'Até 1 salário mínimo',
    '1_to_2': 'De 1 a 2 salários',
    '2_to_3': 'De 2 a 3 salários',
    above_3: 'Mais de 3 salários',
  };
  const workLabels = {
    formal: 'Emprego formal', informal: 'Trabalho informal/autônomo',
    unemployed: 'Desempregado(a)', retired: 'Aposentado(a)/pensionista',
  };
  const eduLabels = {
    fundamental_incomplete: 'Fundamental incompleto', fundamental_complete: 'Fundamental completo',
    high_school_incomplete: 'Médio incompleto',       high_school_complete: 'Médio completo',
    higher_incomplete:      'Superior incompleto',    higher_complete:      'Superior completo',
    postgrad_incomplete:    'Pós-graduação incompleta', postgrad_complete:  'Pós-graduação completa',
  };
  return `
    <ul class="review-list">
      <li><span class="label">Pessoas na casa</span><span class="value">${escapeHtml(s.household_size)}</span></li>
      <li><span class="label">Renda da família</span><span class="value">${escapeHtml(incomeLabels[s.income_range] || '—')}</span></li>
      <li><span class="label">Benefício social</span><span class="value">${s.receives_benefit ? 'Sim' + (s.benefit_details ? ' — ' + escapeHtml(s.benefit_details) : '') : 'Não'}</span></li>
      <li><span class="label">Trabalho do provedor</span><span class="value">${escapeHtml(workLabels[s.provider_work_status] || '—')}</span></li>
      <li><span class="label">Plano de saúde</span><span class="value">${s.has_health_plan ? 'Sim' : 'Não'}</span></li>
      <li><span class="label">Escolaridade</span><span class="value">${escapeHtml(eduLabels[s.provider_education] || '—')}</span></li>
      ${s.observations && s.observations.trim() ? `
        <li><span class="label">Observações</span><span class="value" style="white-space: pre-wrap;">${escapeHtml(s.observations)}</span></li>
      ` : ''}
    </ul>
  `;
}

/* ===== Bind de eventos da etapa atual ===== */
function bindStepEvents() {
  const step = wizard.currentStep;

  if (step === 1) {
    // "Essa triagem é para você?" — define hasGuardianStep diretamente.
    $$('input[name="is_for_self"]').forEach(r => {
      r.onchange = (e) => {
        const isYes = e.target.value === 'yes';
        wizard.data.isForSelf = isYes;
        wizard.hasGuardianStep = !isYes;
        $$('input[name="is_for_self"]').forEach(r2 =>
          r2.closest('.radio-option').classList.toggle('selected', r2.checked)
        );
        // Atualiza só o ajudinha do início — re-render leve do passo
        // pra refletir "do paciente" no label do nome.
        renderStep();
      };
    });

    $('#full_name').oninput = (e) => wizard.data.patient.full_name = e.target.value;
    $('#birth_date').onchange = (e) => {
      wizard.data.patient.birth_date = e.target.value;
    };
    $('#cpf').oninput = (e) => {
      const cursorAtEnd = e.target.selectionStart === e.target.value.length;
      const formatted = formatCPF(e.target.value);
      e.target.value = formatted;
      wizard.data.patient.cpf = formatted;
      if (cursorAtEnd) {
        // Mantém o cursor no final depois de reescrever o valor
        e.target.setSelectionRange(formatted.length, formatted.length);
      }
    };
    $$('input[name="biological_sex"]').forEach(r => {
      r.onchange = (e) => {
        wizard.data.patient.biological_sex = e.target.value;
        $$('input[name="biological_sex"]').forEach(r2 =>
          r2.closest('.radio-option').classList.toggle('selected', r2.checked)
        );
      };
    });

    // Telefone do paciente (existe só quando "é pra você")
    const phoneEl = $('#phone');
    if (phoneEl) {
      phoneEl.oninput = (e) => {
        const formatted = formatPhone(e.target.value);
        e.target.value = formatted;
        wizard.data.patient.phone = formatted;
      };
    }

    // CEP: máscara enquanto digita + busca ViaCEP quando completar 8 dígitos
    const cepEl    = $('#zip_code');
    const cepStatus = $('#cep_status');
    if (cepEl) {
      cepEl.oninput = async (e) => {
        const formatted = formatCEP(e.target.value);
        e.target.value = formatted;
        wizard.data.patient.zip_code = formatted;

        const digits = formatted.replace(/\D/g, '');
        if (digits.length === 8) {
          await lookupCep(digits, cepStatus);
        } else {
          if (cepStatus) cepStatus.textContent = '';
        }
      };
    }

    // Endereço (campos editáveis, podem ser preenchidos manualmente também)
    $('#street')      .oninput = (e) => wizard.data.patient.street       = e.target.value;
    $('#number')      .oninput = (e) => wizard.data.patient.number       = e.target.value;
    $('#complement') .oninput = (e) => wizard.data.patient.complement   = e.target.value;
    $('#neighborhood').oninput = (e) => wizard.data.patient.neighborhood = e.target.value;
    $('#city')        .oninput = (e) => wizard.data.patient.city         = e.target.value;
    $('#state')       .oninput = (e) => {
      const uf = e.target.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2);
      e.target.value = uf;
      wizard.data.patient.state = uf;
    };
  }

  if (step === 2) {
    $('#guardian_name').oninput = (e) => wizard.data.guardian.name = e.target.value;
    $('#guardian_relationship').onchange = (e) => wizard.data.guardian.relationship = e.target.value;
    $('#guardian_phone').oninput = (e) => {
      const formatted = formatPhone(e.target.value);
      e.target.value = formatted;
      wizard.data.guardian.phone = formatted;
    };
    $('#guardian_email').oninput = (e) => wizard.data.guardian.email = e.target.value;
  }

  if (step === 3) {
    $('#account_email').oninput = (e) => wizard.data.account.email = e.target.value;
    $('#password').oninput = (e) => wizard.data.account.password = e.target.value;
    $('#password_confirm').oninput = (e) => wizard.data.account.password_confirm = e.target.value;
    $('#terms').onchange = (e) => wizard.data.account.terms = e.target.checked;
  }

  if (step === 4) {
    $('#family_history_notes').oninput = (e) =>
      wizard.data.patient.family_history_notes = e.target.value;
  }

  if ([5, 6, 7].includes(step)) {
    $$('.indicator-card').forEach(card => {
      const id = card.dataset.indicatorId;
      $$(`input[name="ind_${id}"]`, card).forEach(r => {
        r.onchange = (e) => {
          wizard.data.answers[id] = { answer: e.target.value };
          $$('.radio-option', card).forEach(opt => {
            opt.classList.toggle('selected', $('input', opt).checked);
          });
          card.classList.add('answered');
        };
      });
    });
  }

  if (step === 8) {
    ['photo_front', 'photo_side', 'medical_request'].forEach(kind => {
      const input = $(`#upload_${kind}`);
      if (!input) return;
      input.onchange = async (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;

        // Validação leve no cliente (servidor revalida)
        const MAX = 5 * 1024 * 1024;
        const allowedMimes = kind === 'medical_request'
          ? ['application/pdf', 'image/jpeg', 'image/png']
          : ['image/jpeg', 'image/png'];
        if (file.size > MAX) {
          toast.error(`Arquivo grande demais (máx. 5 MB).`);
          input.value = '';
          return;
        }
        if (!allowedMimes.includes(file.type)) {
          toast.error(`Formato não aceito para "${file.name}".`);
          input.value = '';
          return;
        }

        const statusEl = $(`[data-upload-status="${kind}"]`);
        statusEl.innerHTML = `<span class="text-muted">Enviando...</span>`;
        input.disabled = true;

        try {
          const data = await api.upload('/uploads/create', file, { kind }, (pct) => {
            statusEl.innerHTML = `<span class="text-muted">Enviando... ${pct}%</span>`;
          });
          wizard.data.uploads[kind] = {
            token:         data.token,
            original_name: data.original_name,
            mime_type:     data.mime_type,
            size_bytes:    data.size_bytes,
          };
          const sizeKb = Math.round(data.size_bytes / 1024);
          statusEl.innerHTML = `<span style="color: var(--color-success, #2a7a3e);">✓ Enviado: ${escapeHtml(data.original_name)} (${sizeKb} KB)</span>`;
        } catch (err) {
          wizard.data.uploads[kind] = null;
          statusEl.innerHTML = `<span style="color: var(--color-danger, #c5392f);">✗ ${escapeHtml(err.message || 'Falha ao enviar.')}</span>`;
        } finally {
          input.disabled = false;
        }
      };
    });
  }

  if (step === 9) {
    const s = wizard.data.socioeconomic;

    $('#socio_household_size').oninput = (e) => {
      s.household_size = e.target.value;
    };

    $$('input[name="socio_income_range"]').forEach(r => {
      r.onchange = (e) => {
        s.income_range = e.target.value;
        $$('input[name="socio_income_range"]').forEach(r2 =>
          r2.closest('.radio-option').classList.toggle('selected', r2.checked)
        );
      };
    });

    $$('input[name="socio_receives_benefit"]').forEach(r => {
      r.onchange = (e) => {
        s.receives_benefit = e.target.value === 'yes';
        if (!s.receives_benefit) s.benefit_details = '';
        // Re-renderiza pra mostrar/esconder o campo de detalhes
        renderStep();
      };
    });
    const detEl = $('#socio_benefit_details');
    if (detEl) {
      detEl.oninput = (e) => s.benefit_details = e.target.value;
    }

    $$('input[name="socio_work_status"]').forEach(r => {
      r.onchange = (e) => {
        s.provider_work_status = e.target.value;
        $$('input[name="socio_work_status"]').forEach(r2 =>
          r2.closest('.radio-option').classList.toggle('selected', r2.checked)
        );
      };
    });

    $$('input[name="socio_has_plan"]').forEach(r => {
      r.onchange = (e) => {
        s.has_health_plan = e.target.value === 'yes';
        $$('input[name="socio_has_plan"]').forEach(r2 =>
          r2.closest('.radio-option').classList.toggle('selected', r2.checked)
        );
      };
    });

    $('#socio_education').onchange = (e) => s.provider_education = e.target.value;
    $('#socio_observations').oninput = (e) => s.observations = e.target.value;
  }
}

/* ===== Navegação ===== */
function nextStep() {
  const errors = validateStep(wizard.currentStep);
  if (Object.keys(errors).length > 0) {
    showStepErrors(errors);
    return;
  }

  // Pular etapa 2 (dados do responsável) quando a triagem é para o próprio usuário.
  let next = wizard.currentStep + 1;
  if (next === 2 && !wizard.hasGuardianStep) next = 3;

  // Carregar indicadores antes da etapa 5 (filtrando por sexo)
  if (next === 5 && wizard.indicators.development.length === 0) {
    loadIndicators(wizard.data.patient.biological_sex).then(() => {
      wizard.currentStep = next;
      renderStep();
    });
    return;
  }

  if (next > TOTAL_STEPS) {
    submit();
    return;
  }
  wizard.currentStep = next;
  renderStep();
}

/**
 * Mostra mensagens claras quando o usuário tenta avançar sem preencher.
 *   - 1 erro    → toast direto com a mensagem
 *   - 2+ erros  → bloco inline no topo do corpo do wizard com lista
 */
function showStepErrors(errors) {
  const msgs = Object.values(errors);
  if (msgs.length === 1) {
    toast.error(msgs[0]);
    return;
  }

  // Remove bloco anterior se existir
  const existing = document.getElementById('wizard-step-errors');
  if (existing) existing.remove();

  const box = document.createElement('div');
  box.id = 'wizard-step-errors';
  box.setAttribute('role', 'alert');
  box.style.cssText = `
    background: #fdecec;
    border: 1px solid #f5b5b1;
    color: #842029;
    border-radius: 8px;
    padding: var(--space-3);
    margin-bottom: var(--space-4);
    font-size: var(--fs-sm);
    line-height: 1.5;
  `;
  box.innerHTML = `
    <strong>Não é possível avançar — faltam informações:</strong>
    <ul style="margin: var(--space-2) 0 0 var(--space-4); padding: 0;">
      ${msgs.map(m => `<li>${escapeHtml(m)}</li>`).join('')}
    </ul>
  `;
  const body = $('#wizard-body');
  body.insertBefore(box, body.firstChild);
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function prevStep() {
  let prev = wizard.currentStep - 1;
  if (prev === 2 && !wizard.hasGuardianStep) prev = 1;
  if (prev < 1) return;
  wizard.currentStep = prev;
  renderStep();
}

/* ===== Submissão final ===== */
async function submit() {
  const btn = $('#btn-next');
  btn.disabled = true;
  btn.textContent = 'Enviando...';

  try {
    const answersArray = Object.entries(wizard.data.answers).map(([id, v]) => ({
      indicator_id: parseInt(id, 10),
      answer:       v.answer,
      observation:  v.observation || null,
    }));

    const s = wizard.data.socioeconomic;
    const u = wizard.data.uploads;

    const payload = {
      is_for_self: wizard.data.isForSelf,
      patient: {
        full_name:            wizard.data.patient.full_name,
        birth_date:           wizard.data.patient.birth_date,
        biological_sex:       wizard.data.patient.biological_sex,
        cpf:                  wizard.data.patient.cpf || null,
        // Telefone só vai quando é "pra você". Quando é por outra pessoa,
        // o backend ignora esse campo e usa o telefone do responsável.
        phone:                wizard.data.isForSelf
                                ? (wizard.data.patient.phone || '')
                                : null,
        zip_code:             wizard.data.patient.zip_code     || '',
        street:               wizard.data.patient.street       || '',
        number:               wizard.data.patient.number       || '',
        complement:           wizard.data.patient.complement   || '',
        neighborhood:         wizard.data.patient.neighborhood || '',
        city:                 wizard.data.patient.city         || '',
        state:                (wizard.data.patient.state || '').toUpperCase(),
        family_history_notes: (wizard.data.patient.family_history_notes || '').trim() || null,
      },
      guardian: wizard.hasGuardianStep ? {
        name:         wizard.data.guardian.name,
        relationship: wizard.data.guardian.relationship,
        phone:        wizard.data.guardian.phone,
        email:        wizard.data.guardian.email || null,
      } : null,
      account: {
        email:    wizard.data.account.email,
        password: wizard.data.account.password,
      },
      answers: answersArray,
      upload_tokens: {
        photo_front:     u.photo_front     ? u.photo_front.token     : null,
        photo_side:      u.photo_side      ? u.photo_side.token      : null,
        medical_request: u.medical_request ? u.medical_request.token : null,
      },
      socioeconomic: {
        household_size:       parseInt(s.household_size, 10) || 0,
        income_range:         s.income_range,
        receives_benefit:     !!s.receives_benefit,
        benefit_details:      s.benefit_details || null,
        provider_work_status: s.provider_work_status,
        has_health_plan:      !!s.has_health_plan,
        provider_education:   s.provider_education,
        observations:         (s.observations || '').trim() || null,
      },
    };

    const data = await api.post('/patients/register', payload);

    // Login automático — cookie de sessão já foi setado pelo backend.
    // Aqui guardamos o user só pra exibir nome/role nas telas.
    api.setUser(data.user);

    // Tela de sucesso
    showSuccessScreen(data.message);

  } catch (err) {
    btn.disabled = false;
    btn.textContent = 'Enviar para análise';

    // Erros 409 (conflito): CPF ou email já cadastrado.
    // Volta pra etapa onde o problema está pra a pessoa corrigir.
    if (err.status === 409) {
      const msg = err.message || 'Já existe um cadastro com esses dados.';
      const isCpf = /cpf/i.test(msg);
      const isEmail = /e-?mail/i.test(msg);
      if (isCpf) {
        wizard.currentStep = 1;
        renderStep();
        showStepErrors({ cpf: msg });
        return;
      }
      if (isEmail) {
        wizard.currentStep = 3;
        renderStep();
        showStepErrors({ email: msg });
        return;
      }
      toast.error(msg);
      return;
    }

    // Erros 422 (validação): mostra todos os erros do servidor de uma vez
    // num bloco no topo do corpo do wizard.
    const details = err.details && typeof err.details === 'object' ? err.details : null;
    if (details && Object.keys(details).length > 0) {
      const flat = {};
      Object.entries(details).forEach(([k, v]) => {
        flat[k] = Array.isArray(v) ? v.join(' ') : String(v);
      });
      showStepErrors(flat);
    } else {
      toast.error(err.message || 'Erro ao enviar. Tente novamente.');
    }
  }
}

function showSuccessScreen(message) {
  const card = $('.wizard');
  card.innerHTML = `
    <div class="success-screen">
      <div class="success-icon">✓</div>
      <h1>Recebemos suas informações!</h1>
      <p>${escapeHtml(message || 'Nossa equipe clínica analisou suas respostas. Agora você pode agendar sua consulta com nossa equipe.')}</p>
      <p class="text-small text-muted" style="margin-top: var(--space-3);">
        A consulta será online. O link será enviado pela clínica por WhatsApp ou e-mail próximo à data.
      </p>
      <div class="success-actions">
        <a href="/sgx/frontend/pages/agendar.html" class="btn btn-primary btn-lg">Agendar minha consulta →</a>
        <a href="/sgx/frontend/pages/patient-dashboard.html" class="btn btn-ghost btn-lg">Ir para meu painel</a>
      </div>
    </div>
  `;
}

/* ===== Helpers de escape ===== */
function escapeHtml(s)  { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function escapeAttr(s)  { return escapeHtml(s); }
function formatDate(s)  {
  if (!s) return '';
  const [y, m, d] = s.split('-');
  return `${d}/${m}/${y}`;
}

/* ===== Init ===== */
document.addEventListener('DOMContentLoaded', () => {
  $('#btn-next').onclick = nextStep;
  $('#btn-back').onclick = prevStep;
  renderStep();
});
