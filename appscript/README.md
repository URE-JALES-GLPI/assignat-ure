# Assinatura por e-mail (Google Apps Script)

Página onde a pessoa assina o termo pelo link recebido por e-mail. O GLPI busca a assinatura aqui
a cada 5 minutos: o Google nunca precisa acessar o servidor do GLPI.

## Instalação

1. Com a conta Google institucional, abra <https://script.google.com> e crie um **Novo projeto**.
2. Cole o conteúdo de `Code.gs` no arquivo `Code.gs` do projeto.
3. Crie um arquivo HTML chamado **Assinar** (sem a extensão) e cole o conteúdo de `Assinar.html`.
4. Selecione a função `configurar` e clique em **Executar**. Autorize o acesso ao Drive.
5. Copie o **segredo** mostrado no registro de execução.
6. Clique em **Implantar → Nova implantação → App da Web**, com:
   - **Executar como:** eu
   - **Quem pode acessar:** qualquer pessoa
7. Copie a URL que termina em `/exec`.
8. No GLPI, em **Assignat-ure → Administração → Assinatura por e-mail**, cole a URL e o segredo,
   salve e clique em **Testar conexão**.

Ao alterar o código do script, publique de novo em **Implantar → Gerenciar implantações → Editar →
Nova versão**, mantendo a mesma URL.

## Dados e limites

- CPF/RG e assinatura ficam no Drive só até o GLPI buscá-los. Depois o arquivo é zerado e enviado
  para a lixeira.
- O link vale 7 dias e só pode ser usado uma vez.
- O Apps Script não informa o IP de quem assinou. O comprovante registra e-mail de destino, data,
  hora e o hash do termo. Assinatura desenhada não equivale a certificado ICP-Brasil.
- O Google mostra uma faixa dizendo que a página foi criada por um usuário do Google.
