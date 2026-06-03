Esta pasta guarda os arquivos enviados pelos pacientes
(fotos do rosto e requisições médicas).

Não acesse os arquivos daqui diretamente — use sempre o endpoint
/api/uploads/get.php, que checa autenticação e permissão.

A subpasta _pending/ guarda uploads temporários feitos durante o
wizard de triagem, antes do paciente confirmar o envio. Esses
arquivos são "promovidos" para esta pasta principal quando o
register.php finaliza o cadastro.
