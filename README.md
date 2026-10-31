# sitef-php

Cliente PHP 8.2+ para integrar aplicações com a CliSiTef através de um processo Rust isolado. Produção limitada a **Windows x64**. Linux é usado somente com `testMode: true` e bibliotecas sintéticas próprias; isso não representa suporte nem homologação CliSiTef Linux.

O integrador fornece a DLL x64 e seus arquivos de ambiente. DLL, INI, PDF, certificados, traces e credenciais do fornecedor não acompanham o projeto. O código foi escrito para este SDK, tomando nosso cliente Node como referência; não contém Delphi ou arquivos de projetos GitHub terceiros.

Este é um componente em desenvolvimento, com transporte e ciclo testados. Catálogo de códigos, preservação de dados e implementação de negócio são níveis distintos. Veja [cobertura e limitações](docs/coverage.md).

## Instalação e arquitetura

O PHP core não possui dependências Composer em runtime além de PHP 8.2 e JSON. Execute `composer install` para gerar o autoloader ou use `require '/caminho/sitef-php/autoload.php'`. O nome Composer é `cosmemilton/sitef-php`; isto não significa que já foi publicado no Packagist.

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

Os parâmetros adicionais são strings integrais, sem whitelist que descarte extensões do fornecedor. O SDK não escreve nem reescreve INI.

`Client::connect(Transport, array $configuration, Journal, callable $interaction, ?callable $onExchange, int $bufferSize, int $maxSteps)` permite transportes e persistência próprios. O factory valida plataforma/capabilities antes de configurar a biblioteca.

```php
use Cosmemilton\Sitef\{CancellationToken, Sitef, SitefException};

$cancel = new CancellationToken();
$client = Sitef::create([
    // Caminhos e configuration vêm da aplicação, sem expô-los ao navegador.
    ...$applicationOptions,
    'interaction' => function (array $request, CancellationToken $cancel) use ($applicationUi): array {
        // O desenvolvedor implementa a tela e devolve o resultado da ação humana.
        // O callback deve ter um prazo próprio e observar o token.
        return $applicationUi->collect($request, $cancel);
    },
    'onExchange' => function (array $request, CancellationToken $cancel) use ($applicationProtocol): void {
        // Comando23: retornar imediatamente, sem tela, sleep ou I/O lento.
        if ($request['command'] === 23) return;
        // Persista/agruppe dados necessários. Não registre todo o buffer.
        // Para CB/administrativas, imprima121/122 AGORA e lance falha se não imprimir.
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

`transact()` devolve `status`, `transactionId`, `requiresFinalization` e `fields` como lista ordenada, preservando campos repetidos, `bufferBase64` e `decodingError`. Valores permanecem texto, sem conversão monetária por float. O buffer do comando 0 é ecoado inclusive por bytes brutos. Comandos desconhecidos são encaminhados ao callback; o SDK não escolhe menu, confirma pergunta ou devolve entrada inventada. `Catalog::commandInfo()`, `functionInfo()` e `classifyReturn($code,$context)` consultam o catálogo comum. Positivos, negativos e códigos desconhecidos são preservados. Uma rotina auxiliar pode ter domínio próprio de retorno: por exemplo, presença de pinpad 1 indica dispositivo operacional, pendências 2 é contagem e barcode 3 indica bloco inválido.

Para consultas/rotinas sem pagamento, passe `transact($input, null, ['requiresFinalization' => false])` conforme o contrato da modalidade; a inferência por função ainda não está pronta. Métodos auxiliares: `capabilities()`, `validateBarcode($data)` e `callNative($method,$params)`, limitado aos métodos tipados da ponte. Não aceita nome arbitrário de símbolo nativo.

## Cancelamento, falhas e recuperação

`Client::cancel()` ou `$cancel->cancel()` pede cancelamento cooperativo. A próxima continuação usa -1 e o loop é drenado até o retorno deixar de ser 10000. Uma falha de callback também solicita cancelamento, sem reutilizar o buffer anterior. `Continua=2` é preservado para cancelamento parcial de conta no campo permitido; `10000` para dado previamente coletado exige a confirmação e autorização previstas pelo fornecedor.

PHP é síncrono: o token não interrompe um callback bloqueado ou uma chamada nativa em andamento. O prazo do transporte protege IPC; a aplicação precisa impor prazo ao seu canal de UI e retornar imediatamente no comando 23. O SDK não introduz espera artificial no polling da biblioteca. Processos PHP de requisições HTTP curtas não são uma sessão persistente de PDV: hospede o cliente em worker exclusivo e mantenha um único dono por terminal.

`SitefException` contém `errorCode`, `uncertain`, `nativeStatus` e a causa. Falha de comunicação após iniciar ou durante finalização deixa estado `uncertain`; não repetir a venda. O journal mantém metadados/IDs fiscais originais e a decisão de finalização antes da chamada void. `finalize()` devolve somente `returned:true,remoteConfirmation:'not-reported'`: retorno local não prova confirmação remota.

`recovery()` permite inspecionar o journal. `finalizeRecovery($confirm,$expectedTransactionId,$additionalParameters)` exige reconciliação e conserva decisão/escopo já persistidos. Em `uncertain`, `callNative` permite apenas `pendingCount`, `version` e `capabilities`; consulta detalhada 130/131 na mesma sessão ainda é uma limitação documentada. Não apague locks deixados após crash automaticamente.

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

Validação executada com PHP 8.3.6 Linux e PHP 8.5.12 Windows x64: lint e 18 testes, incluindo corrupção/limites IPC, handshake forjado, timeout, EOF, IDs, journals, falhas e recuperação. Integração PHP → ponte Rust TCP → fixture C própria testou ABI, encoding, eco bruto e finalização void tanto com .so Linux quanto com DLL Windows x64 compilada da mesma fixture. Isso não é homologação SiTef nem teste de transações financeiras reais. O smoke `examples/inspect-windows-x64.php` faz apenas load/capabilities da DLL fornecida pelo integrador, sem configurar terminal ou iniciar pagamento.

O decoder rejeita JSON inválido, BOM e shapes incorretos de frame/result/error. Como o `json_decode` do PHP adota o último valor de uma chave repetida, este cliente não detecta chaves JSON duplicadas; use somente a ponte própria confiável, cujo serializer não as produz. Isso permanece uma limitação do parser, não uma validação de produtor hostil arbitrário.
