Assunto: Instalação de plugin no GLPI (ticketlinksync)

Olá,

Preciso que instalem um plugin no GLPI (ambiente de teste). Segue o
necessário:

1. Arquivo em anexo: `ticketlinksync.zip`.
2. Extrair o zip dentro da pasta `plugins/` da instalação do GLPI, de forma
   que o resultado final seja exatamente:
   `plugins/ticketlinksync/setup.php`
   (ou seja, a pasta extraída deve se chamar `ticketlinksync`, sem
   sufixos como `-main` ou `-master`).
3. Não é necessário criar banco de dados, tabela ou configuração adicional
   — o plugin só registra hooks internos do GLPI.
4. Requisitos: GLPI 10.x ou 11.x, PHP 8.1+.
5. Depois de extrair, eu mesmo consigo ativar pelo painel web, em
   Configurar > Plugins (preciso apenas de um usuário com perfil
   superadmin).

Qualquer erro de PHP relacionado a esse plugin aparecerá no log de erros
do GLPI (`files/_log/php-errors.log`, dentro da instalação) — se algo
falhar, peço que me enviem o conteúdo desse log referente ao horário do
erro.

Obrigado!
