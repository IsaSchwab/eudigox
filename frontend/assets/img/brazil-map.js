(function () {
  const holder = document.getElementById('brazil-map-holder');
  if (!holder) return;
  holder.innerHTML = `
  <svg viewBox="0 0 420 440" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Distribuição por região" style="width:100%; max-width:420px; margin:0 auto; display:block;">
    <defs><filter id="mapShadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="6" stdDeviation="8" flood-color="#14315c" flood-opacity="0.18"/></filter></defs>
    <g filter="url(#mapShadow)">
      <path d="M70 70 L240 60 L250 150 L150 175 L60 160 Z" fill="#c5def5" stroke="#fff" stroke-width="3"/>
      <text x="150" y="115" text-anchor="middle" font-family="Quicksand, sans-serif" font-weight="700" font-size="22" fill="#14315c">11</text>
      <text x="150" y="135" text-anchor="middle" font-family="Nunito Sans, sans-serif" font-size="11" fill="#14315c">NORTE</text>
      <path d="M250 60 L360 90 L355 200 L255 195 L250 150 Z" fill="#9fcaee" stroke="#fff" stroke-width="3"/>
      <text x="305" y="130" text-anchor="middle" font-family="Quicksand, sans-serif" font-weight="700" font-size="22" fill="#14315c">60</text>
      <text x="305" y="150" text-anchor="middle" font-family="Nunito Sans, sans-serif" font-size="11" fill="#14315c">NORDESTE</text>
      <path d="M150 175 L250 150 L255 195 L245 270 L150 265 Z" fill="#7db7e8" stroke="#fff" stroke-width="3"/>
      <text x="195" y="215" text-anchor="middle" font-family="Quicksand, sans-serif" font-weight="700" font-size="22" fill="#fff">76</text>
      <text x="195" y="234" text-anchor="middle" font-family="Nunito Sans, sans-serif" font-size="10" fill="#fff">CENTRO-OESTE</text>
      <path d="M245 270 L255 195 L355 200 L350 300 L270 320 Z" fill="#1f4f86" stroke="#fff" stroke-width="3"/>
      <text x="300" y="255" text-anchor="middle" font-family="Quicksand, sans-serif" font-weight="700" font-size="26" fill="#fff">393</text>
      <text x="300" y="275" text-anchor="middle" font-family="Nunito Sans, sans-serif" font-size="11" fill="#fff">SUDESTE</text>
      <path d="M150 265 L245 270 L270 320 L230 390 L150 360 Z" fill="#2b6cb0" stroke="#fff" stroke-width="3"/>
      <text x="200" y="330" text-anchor="middle" font-family="Quicksand, sans-serif" font-weight="700" font-size="24" fill="#fff">246</text>
      <text x="200" y="349" text-anchor="middle" font-family="Nunito Sans, sans-serif" font-size="11" fill="#fff">SUL</text>
    </g>
  </svg>`;
})();
