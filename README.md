# [URE] Assignat-ure

Central de assinaturas para GLPI 12. O plugin recebe pedidos dos plugins de origem, coleta a assinatura manuscrita na própria interface e guarda um comprovante associado ao usuário GLPI que fez o registro.

## Instalação

O nome interno do plugin é `assignature`. Copie este projeto para `plugins/assignature` na instalação do GLPI (o diretório de desenvolvimento pode continuar como `D:\Projetos\signat-ure`), instale e ative em **Configurar → Plugins**. Requer PHP 8.2+, cURL e GLPI 12. Os arquivos de assinatura ficam na tabela do plugin e são servidos apenas após a verificação das permissões.

Na instalação, o perfil `Super-Admin` recebe acesso de visualização e administração. Ajuste os demais perfis na aba **Assignat-ure** de cada perfil. O administrador do plugin pode, em **Ferramentas → Assignat-ure → Administração**, liberar por usuário e fonte a visualização de pendentes, concluídas e a coleta de assinatura. A permissão do perfil continua necessária; um acesso por usuário sozinho não libera o plugin.

## Vincular o Tralla

1. No Tralla, abra **Configurações** e copie o token da seção Assignat-ure.
2. No Assignat-ure, adicione uma fonte com identificador `tralla`, adaptador **Tralla**, URL `https://SEU-GLPI/plugins/tralla/front/assignature.php` e o token copiado.
3. Sincronize a fonte. As retiradas aprovadas aparecem como pedidos pendentes.
4. No Tralla, defina a URL do Assignat-ure como `https://SEU-GLPI/plugins/assignature/front/request.php`. O Tralla acrescenta `?card=ID` ao link do card.
5. Conceda ao usuário o acesso por perfil e a regra para a fonte Tralla. Para poder coletar uma assinatura, marque **Ver pendentes** e **Pode assinar**.

Após a assinatura, o Assignat-ure salva o comprovante e envia um retorno autenticado ao Tralla. O Tralla conclui automaticamente a retirada de toda a família do card. Se a origem estiver indisponível, o comprovante permanece salvo, o estado do retorno aparece na administração e a tarefa automática tenta reenviá-lo.
Quando um card foi separado em uma família, o Assignat-ure cria um único pedido para o card principal e o mesmo comprovante é aberto pelos demais cards da família.

## Contrato de integração genérica

O adaptador genérico permite cadastrar Protocolo e outros plugins quando eles oferecerem um endpoint HTTP(S) com token. O token configurado no Assignat-ure é enviado apenas no cabeçalho `X-Assignature-Token` e fica criptografado com a chave do GLPI. O endpoint não deve redirecionar.

* `GET ENDPOINT?list=pending&limit=500&offset=0` → `{"ok":true,"data":{"requests":[{"id":"123","code":"P-123","title":"Termo","description":"..."}]}}`. O endpoint deve aplicar `offset` e retornar uma lista vazia ao terminar.
* `GET ENDPOINT?id=123` → `{"ok":true,"data":{"id":"123","status":"pending","title":"Termo","description":"..."}}`.
* `POST ENDPOINT` com JSON `{"action":"signature_completed","id":"123","signer_name":"Nome","signed_at":"AAAA-MM-DD HH:MM:SS","signature_sha256":"..."}` → `{"ok":true,"data":{"completed":true}}`.

O retorno deve ser idempotente: enviar duas vezes a mesma assinatura não pode duplicar a conclusão na origem. O plugin de origem deve validar o token antes de consultar pedidos ou aceitar o retorno. O comprovante fica no Assignat-ure; o hash permite à origem vincular o registro.

O Protocolo ainda precisa implementar esse contrato na própria base de código. Cadastrar uma URL do Protocolo antes disso não faz a sincronização funcionar.
