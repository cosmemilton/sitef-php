# sitef-php 0.2

Cliente PHP 8.2+ para integrar aplicações com a CliSiTef através de um processo Rust isolado. Produção limitada a **Windows x64**. Linux é usado somente com `testMode: true` e bibliotecas sintéticas próprias; isso não representa suporte nem homologação CliSiTef Linux.

O integrador fornece a DLL x64 e seus arquivos de ambiente. DLL, INI, PDF, certificados, traces e credenciais do fornecedor não acompanham o projeto. O código foi escrito para este SDK, tomando nosso cliente Node como referência; não contém Delphi ou arquivos de projetos GitHub terceiros.

Este é um componente em desenvolvimento, com transporte e ciclo testados. Catálogo de códigos, preservação de dados e implementação de negócio são níveis distintos. Veja [cobertura e limitações](docs/coverage.md) e [validação 0.2](docs/validation.md).

## Instalação e arquitetura

O PHP core requer PHP 8.2, JSON e iconv, sem bibliotecas Composer externas em runtime. A conversão de caracteres é estrita e verificada por round trip, sem `TRANSLIT` ou `IGNORE`. Execute `composer install` para gerar o autoloader ou use `require '/caminho/sitef-php/autoload.php'`. O nome Composer é `cosmemilton/sitef-php`; isto não significa que já foi publicado no Packagist.

A ponte própria deve ser compilada para Windows x64 a partir de `native/` conforme o contrato compartilhado. Não carregar uma DLL Win32 em processo x64. O tipo C `long` no Windows x64 continua com quatro bytes; ponteiros têm oito bytes.

No Windows, `stream_select` não funciona com pipes retornados por `proc_open`; os detalhes estão no [manual PHP](https://www.php.net/manual/en/function.stream-select.php). Por isso este cliente abre um listener **127.0.0.1 em porta efêmera**, inicia a ponte filha com argumentos array e `bypass_shell`, e autentica um único canal com nonce aleatória de 256 bits. A ponte recebe `--connect 127.0.0.1:PORT --token-env SITEF_BRIDGE_TOKEN`, lê e remove essa variável antes de carregar a DLL e envia o handshake. O restante é o mesmo protocolo NDJSON do cliente Node. Não há porta fixa, servidor público, shell ou repetição automática de chamadas. Stdout/stderr do filho ficam em NUL; diagnóstico financeiro bruto não vira log. Os sockets têm I/O não bloqueante, limite de frame, correlação de ID, EOF e prazo verificável.

## API

Namespace: `Cosmemilton\Sitef`. A entrada simples é `Sitef::create(array $options): Client`.

| Opção | Contrato |
| --- | --- |
| `bridgeExecutable`, `bridgeDirectory` | Caminhos absolutos da ponte própria e seu diretório |
| `libraryPath` | DLL x64 fornecida pelo integrador, caminho absoluto |
| `workingDirectory` | Diretório opcional de trabalho da biblioteca/INI |
| `stateDirectory` | Diretório local persistente para journal e lock por loja/terminal |
| `configuration` | `server`, `storeId` (8 dígitos), `terminalId` (8 alfanuméricos), `reserved:0`, `additionalParameters` integral |
| `interaction` | `function(array $request, CancellationToken $cancel): array` |
| `onExchange` | Observador opcional de cada troca, incluindo dados e polling |
| `nativeEncoding` | `windows-1252` padrão, `utf-8` ou `ascii` |
| `timeoutSeconds`, `maxFrameBytes` | Prazo IPC padrão 120 s e frame máximo padrão 1 MiB |
| `bufferSize`, `maxSteps` | Buffer nativo mínimo 20000, máximo 1 MiB; proteção de ciclo padrão 100000 passos |
| `testMode` | `false` padrão. `true` somente para fixtures próprias |
| `hooks` | Impressão, armazenamento, assinatura, recursos, dados da aplicação e validações descritos abaixo |
| `receiptStoragePolicy` | `persist` padrão exige armazenamento confirmado; `explicit-return` exige reconhecimento explícito antes de confirmar |
| `monetaryFormats` | Mapa por campo: `decimal-comma` ou `minor-units`, quando a referência não fixa a escala |
| `confirmationNetworkField` | `131` ou `158`, quando a aplicação conhece a fonte correta da rede de confirmação |

Os parâmetros adicionais são strings integrais, sem whitelist que descarte extensões do fornecedor. O SDK não escreve nem reescreve INI.

`Client::connect(Transport, array $configuration, Journal, callable $interaction, ?callable $onExchange, int $bufferSize, int $maxSteps, array $semanticOptions)` permite transportes e persistência próprios. O factory valida plataforma/capabilities antes de configurar a biblioteca.

```php
use Cosmemilton\Sitef\{CancellationToken, Sitef, SitefException};

$cancel = new CancellationToken();
$client = Sitef::create([
    // Caminhos e configuration vêm da aplicação, sem expô-los ao navegador.
    ...$applicationOptions,
    'hooks' => [
        // Callables da aplicação; sucesso de armazenamento/impressão deve ser true.
        'storeReceipt' => $storeReceipt,
        'printReceipt' => $printReceipt,
        'loadReceipt' => $loadReceipt,
        'captureSignature' => $captureSignature,
        'validateTransaction' => $validateTransaction,
    ],
    'interaction' => function (array $request, CancellationToken $cancel) use ($applicationUi): array {
        // O desenvolvedor implementa a tela e devolve o resultado da ação humana.
        // O callback deve ter um prazo próprio e observar o token.
        return $applicationUi->collect($request, $cancel);
    },
    'onExchange' => function (array $request, CancellationToken $cancel) use ($applicationProtocol): void {
        // Comando23: retornar imediatamente, sem tela, sleep ou I/O lento.
        if ($request['command'] === 23) return;
        // Persista/agruppe dados necessários. Não registre todo o buffer.
        // Impressão/armazenamento têm hooks próprios e não devem ser duplicados aqui.
        $applicationProtocol->observe($request);
    },
]);
$result = $client->transact([
    'functionId' => 0, 'amount' => '10,00', 'fiscalDocument' => '123',
    'fiscalDate' => '20261030', 'fiscalTime' => '120000', 'operator' => 'CAIXA',
    'additionalParameters' => '{ExtensaoDoFornecedor=valor}',
], $cancel);
// Trate result.status, recibos e resultado fiscal antes da decisão financeira.
// Se requiresFinalization=true, confirme ou desfaça explicitamente:
if ($result['requiresFinalization']) {
    $client->finalize($applicationSaleWasCompleted);
}
$client->close();
```

`transact()` devolve `status`, `transactionId`, `groupId`, `requiresFinalization`, `confirmationAllowed`, `fields` brutos ordenados e `assessment` com campos tipados, contexto, comprovantes, documentos, NFPAG, saldos, diagnósticos e restrições de produto. `semantics` é alias de `assessment`. Repetições, `bufferBase64` e `decodingError` são preservados. Valores permanecem texto ou `{unscaled,scale}`, sem conversão monetária por float. O buffer do comando 0 é ecoado por bytes brutos. Base64 de uma coleta precisa coincidir com o texto validado na codificação nativa; não permite contornar formato, enum ou tamanho.

Comandos desconhecidos chegam ao callback. O SDK não escolhe menu, confirma pergunta ou inventa entrada. `confirmed:true` é obrigatório para `Continua=10000` e menus `Mc`. O comando 29 usa `resolveApplicationData` se fornecido, ou o callback geral com `kind:'system-input'`; seu dado vem da aplicação, sem tela para o operador.

`Catalog::commandInfo()`, `functionInfo()` e `classifyReturn($code,$context)` consultam o catálogo comum. Positivos, negativos e desconhecidos são preservados. Uma rotina auxiliar pode ter domínio próprio: presença de pinpad 1 indica dispositivo operacional, pendências 2 é contagem e barcode 3 indica bloco inválido.

As políticas vêm de `protocol/functions.json`: funções financeiras e não financeiras conhecidas são inferidas. Um override contraditório falha antes da FFI. Funções desconhecidas ou explicitamente ambíguas exigem `requiresFinalization:true/false` antes de iniciar. Entrypoints de leitura nativos usam sua política não financeira e contexto seguro, independentemente do `functionId` nominal de compatibilidade.

`runFunction(111)` permite rotinas não financeiras sem IDs fiscais fictícios e sem alterar o journal financeiro. Métodos auxiliares: `capabilities()`, `validateBarcode($data)` e `callNative($method,$params)`, limitado aos métodos tipados da ponte. Não aceita nome arbitrário de símbolo nativo.

## Semântica e hooks obrigatórios

`protocol/fields.json` contém definições compartilhadas para 444 linhas da tabela-base, eventos e suplementos. O motor aplica os tipos/formatos declarados e a fase da obrigação. Conteúdo cujo documento não define formato ou unidade exige `resolveField` quando interpretado; o SDK não fabrica uma interpretação específica de rede. `assessment.fields` conserva a definição, o valor e o contexto de cada ocorrência.

As dicas 2355 e a precisão 2470 valem para a próxima coleta. Precisões 1121/1126 ficam no contexto de produto. `AN/ans` aceita texto representável sem inventar uma whitelist de caracteres. Datas, enums documentados, cheque, modalidade, produto 1323 e pares PIX 4156 têm validadores próprios. Produto/Pix com delimitador sem escape documentado é rejeitado. Campos 109/137/138/1319 não recebem escala presumida: configure `monetaryFormats` ou devolva `{unscaled:string,scale:int}` de `resolveField` para aritmética.

| Hook | Obrigação |
| --- | --- |
| `storeReceipt(receipt,context)` | Persistir cada ocorrência por `receipt.id` e retornar true. Recebe `text`/`body` e bytes originais quando disponíveis |
| `printReceipt(receipt,context)` | Retornar true após impressão bem-sucedida. CB/administrativas aguardam isso antes de continuar o loop; outros recibos são impressos antes de confirmar |
| `loadReceipt(metadata,context)` | Recuperar por ID após crash; o SDK confere SHA-256 dos bytes originais ou texto UTF-8 |
| `captureSignature(payload,context)` | Evento 5074: assinatura em papel depois de imprimir e antes de confirmar. 5010 é notificação, sem ação universal inventada |
| `lockResources` / `unlockResources` | Eventos 5042/5043; desbloqueio também ocorre na limpeza após cancelamento. Falta do unlock impede adquirir o lock |
| `resolveApplicationData` | Fornecer dados de sistema sem operador; pode devolver lista estruturada para produtos/PIX |
| `resolveField` | Resolver unidade, estrutura ou contexto não definidos pela referência do produto |
| `onDiagnostic` | Receber diagnósticos de comunicação; nunca dispara repetição financeira |
| `validateTransaction(assessment,context)` | Validar limites/obrigações de negócio presentes. Recebe campos tipados e `productConstraints`; true não apaga violação inequívoca |
| `acceptFallback` | Aceitar explicitamente TEF convencional quando carnê 16/17 não confirma 2361/2363 |
| `resolveConfirmationNetwork` | Determinar a rede quando 131/158 não permitem inferência segura |

Restrições PBM são agrupadas por índice 1011. Preços só são comparados com escala e item conhecidos; violações inequívocas impedem confirmação. Limites 1122/1123 são confrontados com listas de produtos quando o contexto permite. Limite zero ou código menor que tamanho cujo significado exato/máximo não está definido fica `unresolved`, exigindo validação da aplicação. Erro PBM 4039 não é convertido em sucesso por um status final 0.

O segundo argumento dos hooks é um contexto em array. Durante o laço inclui `paymentId`, `protocolContext` e `cancellation` (CancellationToken). Os callbacks gerais `interaction` e `onExchange` mantêm CancellationToken diretamente como segundo argumento.

Falta de hook durante a coleta solicita cancelamento -1 e drena o ciclo. Falha de validação depois de um retorno 0 conserva a aprovação conhecida em `awaiting-finalization`, com `confirmationAllowed:false`; `finalize(false)` continua disponível. O journal não registra uma recusa fictícia para esconder uma aprovação.

`receiptStoragePolicy:'explicit-return'` exige `acknowledgeReceiptStored(id)` e `acknowledgeReceiptPrinted(id)`, ou os hooks correspondentes, antes da confirmação. Sem reconhecimento o SDK bloqueia a FFI. Corpos de comprovantes nunca entram no journal; ficam apenas ID, tipo, hash e estado de armazenamento/impressão.

## Múltiplos pagamentos e finalização seletiva

Um pagamento aprovado mantém a sessão em `awaiting-finalization`. Use `appendPayment($input)` ou `transact(..., options:['appendPayment'=>true])` para acrescentar ao grupo. Na mesma data, o cupom precisa ser o mesmo; na virada de data pode mudar o cupom. Uma tentativa recusada não desfaz aprovações anteriores.

`finalize($confirm)` decide os pendentes por partições de cupom/data, conservando os horários originais. O plano inteiro de decisões é persistido antes da primeira chamada void. Se a segunda partição falhar, a primeira continua registrada como `finalized-local` e a recuperação só retoma intenções ainda pendentes.

`finalizePayment($confirm,$paymentId)` exige índice 161 único. `finalizeNetwork($confirm,$network)` exige fonte de rede explícita ou resolução inequívoca. O SDK não presume que 131 e 158 são equivalentes; uma única fonte presente sem configuração não basta. Parâmetros brutos contendo seletores são rejeitados com orientação para usar a API tipada.

`finalizeWithPayments($confirm,$paymentId,$methods,$expectedTotal,$extra)` anexa NFPAG e decide **todos** os pendentes daquele cupom/data. Não é finalização seletiva. O total é sempre explícito; se os métodos forem null, chama `paymentData(requirements,context)`. O builder exige o limite 730 recebido: ausência não significa 0 ilimitado. Valida tipos, dados obrigatórios, redes restritas por ID 13 e soma decimal exata. O ID 13 não é enviado. A origem do cheque em NFPAG difere dos prefixos do comando 31.

NFPAG pode conter conta de cheque e outros dados privados. Antes da FFI, `storeFinalizationPayload({id,parameters,hash},context)` precisa retornar true. O journal guarda só ID/hash e escopo seguro. Na recuperação, `loadFinalizationPayload({id,hash},context)` devolve a string original; o hash e o seletor do plano persistido devem conferir. Não se reconstruem requisitos ou total do nada depois de um crash.

## Cancelamento, falhas e recuperação

`Client::cancel()` ou `$cancel->cancel()` pede cancelamento cooperativo. A próxima continuação usa -1 e o loop é drenado até o retorno deixar de ser 10000. Uma falha de callback também solicita cancelamento, sem reutilizar o buffer anterior. `Continua=2` é preservado para cancelamento parcial de conta no campo permitido; `10000` para dado previamente coletado exige a confirmação e autorização previstas pelo fornecedor.

PHP é síncrono: o token não interrompe um callback bloqueado ou uma chamada nativa em andamento. O prazo do transporte protege IPC; a aplicação precisa impor prazo ao seu canal de UI e retornar imediatamente no comando 23. O SDK não introduz espera artificial no polling da biblioteca. Processos PHP de requisições HTTP curtas não são uma sessão persistente de PDV: hospede o cliente em worker exclusivo e mantenha um único dono por terminal.

`SitefException` contém `errorCode`, `uncertain`, `nativeStatus` e a causa. Falha de comunicação após iniciar ou durante finalização deixa estado `uncertain`; não repetir a venda. O journal mantém metadados/IDs fiscais originais e a decisão de finalização antes da chamada void. `finalize()` devolve somente `returned:true,remoteConfirmation:'not-reported'`: retorno local não prova confirmação remota.

`recovery()` permite inspecionar o grupo V2, cujo ID é o primeiro pagamento. `queryPendingTransactions()` executa 130; com `['fiscalDocument'=>..., 'fiscalDate'=>...]`, executa 131. A consulta funciona em idle, awaiting ou uncertain sem escrever nem substituir o journal financeiro. Devolve `status`, `count`, `rawCount`, `transactions`/`records` ordenados e campos brutos; erro nativo permanece erro, não contagem.

`finalizeRecovery($confirm,$expectedGroupId,$additionalParameters)` exige reconciliação e conserva decisão/escopo já persistidos. Se houver plano seletivo, irmãos sem decisão ficam pendentes. Falhas de hash/armazenamento antes da FFI mantêm estado uncertain. Não apague locks deixados após crash automaticamente.

Journal V1 migra para V2 sem perder IDs/decisão. Como V1 não comprova recibos, assinatura e validação de negócio, confirmar exige `attestLegacyObligations(payment,context) === true`; desfazer continua permitido quando não contradiz decisão já persistida. Corpo NFPAG em journal legado exige migração externa explícita; o arquivo antigo não é apagado ou reescrito silenciosamente.

O `FileJournal` usa lock exclusivo que sobrevive a crash, escrita temporária no mesmo diretório, flush/fsync e rename sem apagar o arquivo anterior. No Linux de testes sincroniza também o diretório. No Windows a garantia validada é flush/fsync do arquivo e substituição por rename no volume local; isso não equivale a prova de durabilidade contra toda falha de energia/armazenamento. A leitura é limitada a 1 MiB e somente metadados permitidos são gravados. Comprovantes e dados de negócio precisam de persistência separada. Encerramento normal espera até 5 s; se exigir kill, informa resultado incerto e não libera o lock como sucesso normal.

## Modal opcional

O PHP core não depende de React. A aplicação pode implementar callbacks próprios ou usar `@cosmemilton/sitef-ui` no frontend, com `cosmemilton-ui` obrigatória nesse modo. Um canal autenticado da aplicação correlaciona IDs de transação/coleta, aceita uma resposta por coleta, cancela ao desconectar e encaminha somente DTOs necessários. Não exponha caminhos de DLL, configuração, credenciais, tokens ou métodos nativos genéricos ao browser. O canal TCP loopback PHP/Rust é IPC interno de backend e não é a API do navegador.

## Verificação

```sh
composer validate --no-check-publish
composer check
php examples/synthetic.php
# Somente fixture C original própria, nunca DLL do fornecedor:
php tests/native.php /caminho/ponte /caminho/owned-fixture.so
```

A suíte cobre IPC, políticas, tipos/precisões, hooks obrigatórios, comprovantes, assinatura, produtos, múltiplos pagamentos, planos seletivos, migração e recuperação. Integração PHP → ponte Rust TCP → fixture C própria testa ABI, encoding, eco bruto e finalização void com .so Linux e DLL Windows x64. Isso não é homologação SiTef nem teste de transações financeiras reais. O smoke `examples/inspect-windows-x64.php` faz apenas load/capabilities da DLL fornecida pelo integrador, sem configurar terminal ou iniciar pagamento.

O decoder rejeita JSON inválido, BOM e shapes incorretos de frame/result/error. Como o `json_decode` do PHP adota o último valor de uma chave repetida, este cliente não detecta chaves JSON duplicadas; use somente a ponte própria confiável, cujo serializer não as produz. Isso permanece uma limitação do parser, não uma validação de produtor hostil arbitrário.
