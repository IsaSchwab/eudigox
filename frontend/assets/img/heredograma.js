/* =====================================================================
   Heredograma da SXF — ilustração própria (estilo Eu Digo X)
   Mostra de forma simplificada como a alteração no gene FMR1 pode
   ser transmitida. Didático, não substitui aconselhamento genético.
   ===================================================================== */
(function () {
  const holder = document.getElementById('heredograma-holder');
  if (!holder) return;

  const svg = `
  <svg viewBox="0 0 860 420" xmlns="http://www.w3.org/2000/svg" role="img"
       aria-label="Heredograma simplificado da Síndrome do X Frágil"
       style="width:100%; max-width:860px; margin:0 auto; display:block; background:var(--color-surface); border:1px solid var(--color-border); border-radius:18px; padding:8px;">
    <defs>
      <style>
        .lbl { font-family: 'Nunito Sans', sans-serif; font-size: 13px; fill:#14315c; }
        .cap { font-family: 'Quicksand', sans-serif; font-weight:700; font-size: 13px; }
        .lnk { stroke:#9fcaee; stroke-width:2.5; fill:none; }
      </style>
    </defs>

    <!-- Legenda -->
    <g transform="translate(24,24)">
      <circle cx="10" cy="10" r="9" fill="#c5def5" stroke="#2b6cb0" stroke-width="2"/>
      <text x="26" y="14" class="lbl">Típico</text>
      <circle cx="120" cy="10" r="9" fill="#7db7e8" stroke="#2b6cb0" stroke-width="2"/>
      <text x="136" y="14" class="lbl">Pré-mutação</text>
      <circle cx="270" cy="10" r="9" fill="#1f4f86" stroke="#14315c" stroke-width="2"/>
      <text x="286" y="14" class="lbl">Mutação completa</text>
      <rect x="450" y="2" width="16" height="16" fill="#fff" stroke="#14315c" stroke-width="2"/>
      <text x="472" y="14" class="lbl">Homem</text>
      <circle cx="560" cy="10" r="9" fill="#fff" stroke="#14315c" stroke-width="2"/>
      <text x="576" y="14" class="lbl">Mulher</text>
    </g>

    <!-- Geração 1 (avós) -->
    <line x1="170" y1="90" x2="250" y2="90" class="lnk"/>
    <rect x="140" y="74" width="32" height="32" fill="#fff" stroke="#14315c" stroke-width="2.5"/>
    <text x="156" y="124" text-anchor="middle" class="lbl">Avô</text>
    <circle x="266" cy="90" cx="266" r="17" fill="#7db7e8" stroke="#2b6cb0" stroke-width="2.5"/>
    <text x="266" y="124" text-anchor="middle" class="lbl">Avó (pré)</text>

    <!-- desce para geração 2 -->
    <line x1="210" y1="90" x2="210" y2="150" class="lnk"/>
    <line x1="120" y1="150" x2="330" y2="150" class="lnk"/>

    <!-- Geração 2 (pais/tios) -->
    <line x1="120" y1="150" x2="120" y2="178" class="lnk"/>
    <circle cx="120" cy="195" r="17" fill="#7db7e8" stroke="#2b6cb0" stroke-width="2.5"/>
    <text x="120" y="230" text-anchor="middle" class="lbl">Mãe (pré)</text>

    <line x1="330" y1="150" x2="330" y2="178" class="lnk"/>
    <rect x="314" y="178" width="32" height="32" fill="#fff" stroke="#14315c" stroke-width="2.5"/>
    <text x="330" y="230" text-anchor="middle" class="lbl">Tio</text>

    <!-- casamento mãe + pai -->
    <line x1="137" y1="195" x2="210" y2="195" class="lnk"/>
    <rect x="210" y="179" width="32" height="32" fill="#fff" stroke="#14315c" stroke-width="2.5"/>
    <text x="226" y="230" text-anchor="middle" class="lbl">Pai</text>

    <!-- desce para geração 3 -->
    <line x1="180" y1="211" x2="180" y2="270" class="lnk"/>
    <line x1="90" y1="270" x2="430" y2="270" class="lnk"/>

    <!-- Geração 3 (filhos) -->
    <line x1="90" y1="270" x2="90" y2="298" class="lnk"/>
    <circle cx="90" cy="315" r="17" fill="#c5def5" stroke="#2b6cb0" stroke-width="2.5"/>
    <text x="90" y="350" text-anchor="middle" class="lbl">Filha típica</text>

    <line x1="210" y1="270" x2="210" y2="298" class="lnk"/>
    <circle cx="210" cy="315" r="17" fill="#7db7e8" stroke="#2b6cb0" stroke-width="2.5"/>
    <text x="210" y="350" text-anchor="middle" class="lbl">Filha (pré)</text>

    <line x1="330" y1="270" x2="330" y2="298" class="lnk"/>
    <rect x="314" y="298" width="34" height="34" fill="#1f4f86" stroke="#14315c" stroke-width="2.5"/>
    <text x="331" y="352" text-anchor="middle" class="lbl">Filho com SXF</text>

    <line x1="430" y1="270" x2="430" y2="298" class="lnk"/>
    <rect x="414" y="298" width="34" height="34" fill="#fff" stroke="#14315c" stroke-width="2.5"/>
    <text x="431" y="352" text-anchor="middle" class="lbl">Filho típico</text>

    <!-- nota -->
    <text x="600" y="200" class="lbl" style="font-size:12px;">
      <tspan x="600" dy="0">A mãe portadora da pré-mutação</tspan>
      <tspan x="600" dy="20">pode transmitir a expansão, que</tspan>
      <tspan x="600" dy="20">aumenta entre as gerações e pode</tspan>
      <tspan x="600" dy="20">chegar à mutação completa nos filhos.</tspan>
      <tspan x="600" dy="28" style="fill:#2b6cb0; font-weight:700;">Ilustração didática — o</tspan>
      <tspan x="600" dy="18" style="fill:#2b6cb0; font-weight:700;">aconselhamento genético orienta</tspan>
      <tspan x="600" dy="18" style="fill:#2b6cb0; font-weight:700;">cada caso individualmente.</tspan>
    </text>
  </svg>`;

  holder.innerHTML = svg;
})();
