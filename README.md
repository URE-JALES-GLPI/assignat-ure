# [URE] Assignat-ure

Central de assinaturas para GLPI 12. O plugin recebe pedidos dos plugins de origem, permite assinatura digital em lote ou assinatura em papel e guarda um comprovante associado ao usuário GLPI que fez o registro.

## Instalação

O nome interno do plugin é `assignature`. A pasta no servidor **precisa** ser `plugins/assignature`, mesmo que o repositório GitHub se chame `assignat-ure` e o diretório de desenvolvimento seja `D:\Projetos\signat-ure`. Os arquivos `setup.php` e `hook.php` devem ficar diretamente nessa pasta.

Para uma instalação nova, execute a partir da pasta `plugins` do GLPI:

```bash
git clone https://github.com/poiattileo/assignat-ure.git assignature
```

Se você já clonou em `plugins/assignat-ure`, renomeie a pasta com `mv assignat-ure assignature` (verifique antes se `plugins/assignature` ainda não existe). Os próximos `git pull` continuam funcionando dentro da pasta renomeada. Depois, instale e ative em **Configurar → Plugins**. A aba **Instalado** mostra os plugins já instalados; apenas copiar ou atualizar os arquivos não executa a instalação. Requer PHP 8.2+, cURL e GLPI 12. As assinaturas digitais ficam na tabela do plugin; os arquivos enviados ficam em `GLPI_PLUGIN_DOC_DIR/assignature`, fora da pasta pública, e são servidos apenas após a verificação das permissões.

Ao atualizar, faça `git pull` no diretório `plugins/assignature` e execute **Atualizar** na tela de plugins do GLPI. A versão 0.3 cria o cadastro de assinaturas dos técnicos, vincula cada cadastro aos plugins de origem e guarda uma cópia da identificação e assinatura do técnico em cada novo lote. Os comprovantes anteriores permanecem disponíveis. Depois, reative o plugin se o GLPI solicitar.

Na instalação, o perfil `Super-Admin` recebe acesso de visualização e administração. Ajuste os demais perfis na aba **Assignat-ure** de cada perfil. O administrador do plugin pode, em **Ferramentas → Assignat-ure → Administração**, liberar por usuário e fonte a visualização de pendentes, concluídas e a coleta de assinatura. A permissão do perfil continua necessária; um acesso por usuário sozinho não libera o plugin.

## Vincular o Tralla

1. No Tralla, abra **Configurações** e copie o token da seção Assignat-ure.
2. No Assignat-ure, adicione uma fonte com identificador `tralla`, adaptador **Tralla**, URL `https://SEU-GLPI/plugins/tralla/front/assignature.php` e o token copiado.
3. Salve a fonte. O Assignat-ure sincroniza imediatamente, tenta novamente ao abrir o painel (no máximo uma vez por minuto) e também pela tarefa automática. Cada card na lista de retirada aparece como um pedido pendente. Se a conexão falhar, o erro aparece na administração e no painel do administrador.
4. No Tralla, defina a URL do Assignat-ure como `https://SEU-GLPI/plugins/assignature/front/request.php`. O Tralla acrescenta `?card=ID` ao link do card.
5. Conceda ao usuário o acesso por perfil e a regra para a fonte Tralla. Para poder coletar uma assinatura, marque **Ver pendentes** e **Pode assinar**.

Após a assinatura, o Assignat-ure salva o comprovante e envia um retorno autenticado ao Tralla. O Tralla conclui automaticamente apenas a retirada do card assinado. Se a origem estiver indisponível, o comprovante permanece salvo, o estado do retorno aparece na administração e a tarefa automática tenta reenviá-lo.
Cada card na lista de retirada gera seu próprio pedido e comprovante, inclusive quando pertence à mesma família de cards.

## Assinar um ou vários pedidos

Na administração, cadastre o nome completo, CPF ou RG validado e a assinatura desenhada de cada técnico ou funcionário. Marque os plugins de origem onde o cadastro pode ser usado. É possível editar ou desativar um cadastro; lotes já criados mantêm uma cópia dos dados anteriores. Em um lote com pedidos de vários plugins, aparecem apenas os técnicos liberados para todos eles.

Na lista de assinaturas, clique em **Assinar em lote** para ativar a seleção, escolha até 100 pedidos pendentes e clique em **Continuar com selecionados**. Ao abrir um pedido isolado, use **Escolher como assinar**. Um lote associa uma pessoa e um técnico cadastrado a todos os pedidos selecionados, mas cada pedido continua recebendo seu próprio retorno para o plugin de origem.

* **Assinar agora:** escolha o técnico com assinatura cadastrada para a origem, informe o nome completo de quem retira, escolha CPF ou RG, digite o documento e desenhe a assinatura. O CPF recebe a máscara `000.000.000-00` e é validado pelos dígitos verificadores no navegador e no servidor. O RG é mascarado conforme o comprimento; sua validação é apenas de formato, porque as regras variam por estado. Os documentos são criptografados com a chave do GLPI. Ao concluir, o termo é enviado automaticamente à impressora em **2 cópias**.
* **Assinar depois:** escolha o técnico cadastrado. O termo reúne todos os pedidos, inclui a identificação e assinatura do técnico e é enviado diretamente à fila CUPS configurada em **1 cópia**. A pessoa que retira preenche seus dados e assina o papel à mão. Depois da impressão, informe o nome e documento dessa pessoa e envie PDF, PNG ou JPG (até 10 MB). Os pedidos serão marcados como assinados. O arquivo é salvo no armazenamento privado do GLPI e verificado por SHA-256 ao ser aberto. Se o termo não for mais usado, cancele-o para liberar os pedidos.

Na instalação desta unidade, a fila CUPS padrão é `HP_PeB`. Confira ou altere o nome em **Assignat-ure → Administração → Impressão direta no servidor**. O termo segue um visual de comprovante com identificação, lista de equipamentos, status, serviços e duas áreas de assinatura. Quando ocupar mais de uma página, o trabalho solicita impressão **frente e verso pela borda longa**. Se a impressão falhar, o lote permanece aberto para tentar novamente. O envio do arquivo da modalidade em papel fica bloqueado até o servidor receber a confirmação do trabalho da fila CUPS.

Ao abrir **Assinaturas**, a aba **Pendentes** aparece por padrão. As abas **Todas** e **Concluídas** permanecem disponíveis.

## Contrato de integração genérica

O adaptador genérico permite cadastrar Protocolo e outros plugins quando eles oferecerem um endpoint HTTP(S) com token. O token configurado no Assignat-ure é enviado apenas no cabeçalho `X-Assignature-Token` e fica criptografado com a chave do GLPI. O endpoint não deve redirecionar.

* `GET ENDPOINT?list=pending&limit=500&offset=0` → `{"ok":true,"data":{"requests":[{"id":"123","code":"P-123","title":"Termo","description":"..."}]}}`. O endpoint deve aplicar `offset` e retornar uma lista vazia ao terminar.
* `GET ENDPOINT?id=123` → `{"ok":true,"data":{"id":"123","status":"pending","title":"Termo","description":"..."}}`.
* `POST ENDPOINT` com JSON `{"action":"signature_completed","id":"123","signer_name":"Nome","signed_at":"AAAA-MM-DD HH:MM:SS","signature_sha256":"..."}` → `{"ok":true,"data":{"completed":true}}`.

O retorno deve ser idempotente: enviar duas vezes a mesma assinatura não pode duplicar a conclusão na origem. O plugin de origem deve validar o token antes de consultar pedidos ou aceitar o retorno. O comprovante fica no Assignat-ure; o hash permite à origem vincular o registro.

O Protocolo ainda precisa implementar esse contrato na própria base de código. Cadastrar uma URL do Protocolo antes disso não faz a sincronização funcionar.
