# API de mensagens do WhatsApp

Esta API permite que uma aplicação externa envie mensagens pelo WhatsApp da Melo & Zanchettin e consulte as mensagens trocadas com um número. Ela oferece as mesmas ações da tela "Enviar mensagem" do painel.

- **URL base em produção:** `https://wa.meloezanchettin.com/api/v1` (enquanto o domínio não estiver ativo, `https://melo-wa.laravel.cloud/api/v1`)
- **Formato:** JSON em UTF-8, nas requisições e nas respostas
- **Datas:** ISO 8601 em UTC, por exemplo `2026-10-08T18:35:36Z`

## Como o WhatsApp limita os envios

Três regras da Meta determinam o que a API aceita. Vale conhecê-las antes de integrar.

1. **Janela de 24 horas.** Texto livre só é entregue a um número que escreveu para a empresa nas últimas 24 horas. Cada mensagem recebida do número reabre a janela por mais 24 horas. Ler uma mensagem não conta; é preciso responder.
2. **Templates.** Fora da janela, só é possível enviar um template (modelo de mensagem) aprovado pela Meta. É assim que se inicia uma conversa. Um toque do destinatário num botão de resposta do template conta como mensagem recebida e abre a janela.
3. **Números de teste.** Enquanto a conta usar o número de teste da Meta, só recebem mensagens os números cadastrados na lista de destinatários do painel da Meta. Para os demais, a Meta recusa o envio com o erro `131030`.

O fluxo típico de uma integração é: enviar um template, aguardar a resposta do destinatário consultando o histórico do número e, com a janela aberta, continuar a conversa com texto livre.

## Autenticação

Toda requisição precisa do cabeçalho `Authorization` com um token de acesso:

```http
Authorization: Bearer mz_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Accept: application/json
```

Sem token, ou com um token inválido ou revogado, a resposta é `401`:

```json
{
  "message": "Token de acesso ausente, inválido ou revogado.",
  "error": "unauthenticated"
}
```

### Gerenciar tokens

Os tokens são criados e revogados por comandos Artisan, executados por quem administra a aplicação. Em produção, rode-os na aba de comandos do ambiente no Laravel Cloud; no ambiente local, prefixe com `./vendor/bin/sail`.

| Comando | O que faz |
| --- | --- |
| `php artisan api-token:create "Nome da aplicação"` | Cria um token e mostra o valor **uma única vez** |
| `php artisan api-token:list` | Lista os tokens, com situação e data do último uso |
| `php artisan api-token:revoke 3` | Revoga o token número 3; ele deixa de funcionar na hora |

A aplicação guarda apenas o hash do token. Se o valor for perdido, crie outro token e revogue o antigo. Use um token por aplicação externa, para poder revogar um sem afetar os demais.

### Limite de requisições

Cada token pode fazer até 120 requisições por minuto. Acima disso a resposta é `429`, com o cabeçalho `Retry-After` indicando em quantos segundos tentar de novo.

## Números de telefone

Informe os números só com dígitos, com DDI e DDD: `5567999990000`. No corpo do envio, espaços, parênteses, traços e o sinal de mais são removidos automaticamente. Nos endereços (`/numbers/{phone}/...`), use apenas dígitos, entre 10 e 15.

O WhatsApp identifica celulares brasileiros ora com, ora sem o nono dígito. A API trata `5567999990000` e `556799990000` como o mesmo número.

## A mensagem

Os endpoints devolvem mensagens neste formato:

```json
{
  "id": 42,
  "wamid": "wamid.HBgMNTU2Nzk4MjgwOTEyFQIAERgSM0E4...",
  "direction": "outbound",
  "phone": "5567999990000",
  "type": "template",
  "template": "rf_link_v2",
  "body": "Olá, Maria!\n\nConforme combinado, segue o link...",
  "status": "delivered",
  "status_at": "2026-10-08T18:35:41Z",
  "error": null,
  "sent_at": "2026-10-08T18:35:36Z",
  "created_at": "2026-10-08T18:35:36Z"
}
```

| Campo | Descrição |
| --- | --- |
| `id` | Identificador da mensagem nesta API |
| `wamid` | Identificador da mensagem no WhatsApp; `null` se o envio falhou |
| `direction` | `outbound` (enviada pela empresa) ou `inbound` (recebida do número) |
| `phone` | Número do outro lado da conversa |
| `type` | `text` ou `template` nas enviadas; nas recebidas, o tipo informado pelo WhatsApp (`text`, `button`, `image` etc.) |
| `template` | Nome do template, quando `type` é `template` |
| `body` | Texto da mensagem. Em templates, o texto já com as variáveis preenchidas. `null` em mensagens recebidas sem texto, como imagens |
| `status` | Situação das enviadas (tabela abaixo); `null` nas recebidas |
| `status_at` | Quando a situação mudou pela última vez |
| `error` | `null`, ou um objeto `{ "code": 131030, "message": "..." }` com o erro informado pela Meta |
| `sent_at` | Quando a mensagem foi enviada ou recebida |

### Situações de uma mensagem enviada

| `status` | Significado |
| --- | --- |
| `accepted` | A Meta aceitou a mensagem. Ainda não há confirmação de envio |
| `sent` | Enviada ao WhatsApp do destinatário |
| `delivered` | Entregue no aparelho do destinatário |
| `read` | Lida pelo destinatário (depende das configurações de privacidade dele) |
| `failed` | Não foi enviada ou não pôde ser entregue; veja `error` |

A situação avança conforme a Meta notifica a aplicação. Uma mensagem pode ser aceita e falhar depois; nesse caso `status` passa a `failed` e `error` traz o motivo.

## Endpoints

### Listar templates

`GET /templates`

Devolve os templates aprovados que podem ser enviados pela API: os de texto, com variáveis numeradas no corpo e botões fixos. Templates com imagem, vídeo, documento, carrossel ou botão de link variável não são listados nem aceitos no envio.

```bash
curl https://melo-wa.laravel.cloud/api/v1/templates \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

Resposta `200`:

```json
{
  "data": [
    {
      "name": "rf_link_v2",
      "language": "pt_BR",
      "body": "Olá, {{1}}!\n\nConforme combinado, segue o link para realizar o reconhecimento facial referente ao seu cadastro:\n\n{{2}}\n\n...",
      "variables": 2,
      "buttons": ["Concluído"]
    }
  ]
}
```

`variables` é a quantidade de valores que o envio precisa informar em `template.parameters`. A lista é atualizada a partir da Meta a cada minuto, então um template recém-aprovado pode levar até um minuto para aparecer.

### Enviar mensagem

`POST /messages`

| Campo | Tipo | Obrigatório | Descrição |
| --- | --- | --- | --- |
| `to` | string | sim | Número do destinatário, com DDI e DDD |
| `type` | string | sim | `text` ou `template` |
| `text` | string | se `type` for `text` | Texto da mensagem, até 4096 caracteres |
| `template.name` | string | se `type` for `template` | Nome do template, como em `GET /templates` |
| `template.language` | string | se `type` for `template` | Idioma do template, como em `GET /templates` (por exemplo `pt_BR`) |
| `template.parameters` | lista de strings | se o template tiver variáveis | Valores de `{{1}}`, `{{2}}`..., na ordem. A quantidade deve ser igual a `variables` |
| `name` | string | não | Nome do destinatário, usado só para identificá-lo no painel quando o número é novo |

Os valores de `template.parameters` não podem conter quebra de linha, tabulação nem mais de quatro espaços seguidos, por exigência da Meta.

Enviar um template:

```bash
curl -X POST https://melo-wa.laravel.cloud/api/v1/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: cadastro-8841-link" \
  -d '{
    "to": "5567999990000",
    "name": "Maria Souza",
    "type": "template",
    "template": {
      "name": "rf_link_v2",
      "language": "pt_BR",
      "parameters": ["Maria", "https://exemplo.com/rf/abc123"]
    }
  }'
```

Enviar texto livre (só com a janela de 24 horas aberta):

```bash
curl -X POST https://melo-wa.laravel.cloud/api/v1/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"to": "5567999990000", "type": "text", "text": "Recebemos, obrigado!"}'
```

Resposta `201`, com a mensagem em `data` e `status` igual a `accepted`:

```json
{
  "data": {
    "id": 42,
    "wamid": "wamid.HBgMNTU2Nzk4MjgwOTEyFQIAERgSM0E4...",
    "direction": "outbound",
    "phone": "5567999990000",
    "type": "template",
    "template": "rf_link_v2",
    "body": "Olá, Maria!\n\nConforme combinado, segue o link...",
    "status": "accepted",
    "status_at": "2026-10-08T18:35:36Z",
    "error": null,
    "sent_at": "2026-10-08T18:35:36Z",
    "created_at": "2026-10-08T18:35:36Z"
  }
}
```

Guarde o `id`: é com ele que se acompanha a entrega em `GET /messages/{id}`.

#### Quando o envio não acontece

| Status | `error` | Situação | A mensagem fica registrada? |
| --- | --- | --- | --- |
| `409` | `service_window_closed` | Texto livre para um número com a janela de 24 horas fechada. Envie um template | Não |
| `422` | (erros de validação) | Campo ausente ou inválido, template inexistente ou quantidade de variáveis errada | Não |
| `422` | `meta_rejected` | A Meta recusou a mensagem. O motivo vem em `data.error` | Sim, com `status` `failed` |
| `502` | `meta_unavailable` | Não foi possível falar com a Meta. Tente de novo em instantes | Sim, se a falha foi no envio; não, se foi ao consultar os templates |

Exemplo de recusa pela Meta (`422`):

```json
{
  "message": "A Meta recusou a mensagem.",
  "error": "meta_rejected",
  "data": {
    "id": 43,
    "wamid": null,
    "direction": "outbound",
    "phone": "5567999990000",
    "type": "template",
    "template": "rf_link_v2",
    "body": "Olá, Maria!...",
    "status": "failed",
    "status_at": "2026-10-08T18:40:02Z",
    "error": {
      "code": 131030,
      "message": "Recipient phone number not in allowed list"
    },
    "sent_at": null,
    "created_at": "2026-10-08T18:40:02Z"
  }
}
```

Exemplo de erro de validação (`422`):

```json
{
  "message": "Este template exige 2 variável(is) e foram enviadas 1.",
  "errors": {
    "template.parameters": ["Este template exige 2 variável(is) e foram enviadas 1."]
  }
}
```

#### Evitar envio duplicado

Se a sua aplicação repetir uma requisição por falha de rede, o destinatário pode receber a mesma mensagem duas vezes. Para evitar isso, envie o cabeçalho `Idempotency-Key` com um identificador único da operação (até 255 caracteres), por exemplo o código do pedido.

- Se uma mensagem com a mesma chave já foi aceita para o mesmo token, a API não envia de novo: devolve a mensagem original com status `200`.
- Se a tentativa anterior falhou, a chave fica livre e a nova requisição é enviada normalmente.

### Consultar uma mensagem

`GET /messages/{id}`

Devolve a mensagem com a situação atual. Use para acompanhar a entrega de uma mensagem enviada.

```bash
curl https://melo-wa.laravel.cloud/api/v1/messages/42 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

Resposta `200`: `{ "data": { ...mensagem... } }`. Se o `id` não existir, `404`.

### Listar as mensagens de um número

`GET /numbers/{phone}/messages`

Devolve as mensagens trocadas com o número, enviadas e recebidas, com paginação. É por aqui que a aplicação externa lê as respostas do destinatário.

| Parâmetro | Descrição | Padrão |
| --- | --- | --- |
| `direction` | `inbound` para só as recebidas, `outbound` para só as enviadas | todas |
| `since` | Só mensagens registradas a partir desta data (ISO 8601) | sem limite |
| `after_id` | Só mensagens com `id` maior que este | sem limite |
| `order` | `desc` (mais recentes primeiro) ou `asc` (mais antigas primeiro) | `desc` |
| `per_page` | Mensagens por página, de 1 a 100 | 25 |
| `page` | Número da página | 1 |

```bash
curl "https://melo-wa.laravel.cloud/api/v1/numbers/5567999990000/messages?direction=inbound&per_page=50" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

Resposta `200`:

```json
{
  "data": [
    {
      "id": 44,
      "wamid": "wamid.HBgMNTU2Nzk4MjgwOTEyFQIAEhggQUM5...",
      "direction": "inbound",
      "phone": "5567999990000",
      "type": "button",
      "template": null,
      "body": "Concluído",
      "status": null,
      "status_at": null,
      "error": null,
      "sent_at": "2026-10-08T18:52:10Z",
      "created_at": "2026-10-08T18:52:11Z"
    }
  ],
  "links": {
    "first": "https://melo-wa.laravel.cloud/api/v1/numbers/5567999990000/messages?direction=inbound&per_page=50&page=1",
    "last": "https://melo-wa.laravel.cloud/api/v1/numbers/5567999990000/messages?direction=inbound&per_page=50&page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 50,
    "total": 1
  }
}
```

`meta` traz outros campos de paginação além dos mostrados. Um número sem nenhuma mensagem devolve `data` vazio, não `404`.

**Para buscar só o que chegou de novo**, guarde o maior `id` já processado e consulte com `after_id` e `order=asc`:

```bash
curl "https://melo-wa.laravel.cloud/api/v1/numbers/5567999990000/messages?direction=inbound&after_id=44&order=asc" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

As respostas por botão de um template chegam com `type` igual a `button` e o texto do botão em `body` (por exemplo `Sim`, `Não` ou `Concluído`).

### Consultar a janela de 24 horas

`GET /numbers/{phone}/window`

Informa se o número pode receber texto livre agora.

```bash
curl https://melo-wa.laravel.cloud/api/v1/numbers/5567999990000/window \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

Resposta `200`:

```json
{
  "data": {
    "phone": "5567999990000",
    "open": true,
    "last_inbound_at": "2026-10-08T18:52:10Z",
    "closes_at": "2026-10-09T18:52:10Z"
  }
}
```

Para um número que nunca escreveu para a empresa, `open` é `false` e as duas datas são `null`.

## Resumo dos códigos de resposta

| Status | Quando |
| --- | --- |
| `200` | Consulta bem-sucedida, ou envio repetido com uma `Idempotency-Key` já usada |
| `201` | Mensagem aceita pela Meta |
| `401` | Token ausente, inválido ou revogado |
| `404` | Mensagem inexistente, ou número fora do formato no endereço |
| `409` | Texto livre com a janela de 24 horas fechada |
| `422` | Dados inválidos, ou mensagem recusada pela Meta (`error` igual a `meta_rejected`) |
| `429` | Limite de requisições por minuto excedido |
| `502` | Falha de comunicação com a Meta |

Todas as respostas de erro trazem o campo `message` com uma explicação em português.

## Erros da Meta mais comuns

Quando a Meta recusa ou não entrega uma mensagem, o código dela vem em `error.code`.

| Código | Significado | O que fazer |
| --- | --- | --- |
| `131030` | O destinatário não está na lista de números permitidos do número de teste | Cadastrar o número no painel da Meta, ou usar o número de produção |
| `131047` | Passaram mais de 24 horas desde a última mensagem do destinatário | Enviar um template |
| `131026` | A mensagem não pôde ser entregue (número sem WhatsApp, versão antiga do aplicativo, entre outros) | Conferir o número |
| `131005` / `190` | Problema com o token de acesso da empresa junto à Meta | Avisar quem administra a aplicação; não é um problema do seu token |
| `132000` | Quantidade de variáveis diferente da exigida pelo template | Conferir `template.parameters` |
| `132001` | Template inexistente ou não aprovado no idioma informado | Conferir `GET /templates` |

A lista completa está na documentação da Meta: <https://developers.facebook.com/documentation/business-messaging/whatsapp/support/error-codes>.

## O que a API não faz

- **Não avisa quando chega uma mensagem.** A aplicação externa precisa consultar `GET /numbers/{phone}/messages` periodicamente.
- **Não expõe o cadastro de contatos** do painel. Os destinatários são identificados só pelo número.
- **Não envia mídia** (imagem, áudio, documento) nem templates com mídia.
