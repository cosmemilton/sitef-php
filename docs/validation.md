# Validação 0.2

Verificação executada em 30/10/2026, exclusivamente com transportes falsos e bibliotecas C sintéticas próprias. Não foram executadas transações financeiras reais. Estes resultados não constituem homologação SiTef.

| Ambiente | Verificação | Resultado |
| --- | --- | --- |
| Windows x64, PHP 8.5.12 portátil | Lint dos fontes PHP | Aprovado |
| Windows x64, PHP 8.5.12 portátil | `php tests/run.php` | 49 testes, 0 falhas |
| Windows x64 | PHP → ponte Rust TCP → DLL C sintética x64 | Aprovado |
| WSL, PHP 8.3.6 | `composer validate --no-check-publish` | Aprovado |
| WSL, PHP 8.3.6 | `composer dump-autoload --optimize --strict-psr` | Aprovado, 19 classes |
| WSL, PHP 8.3.6 | `composer check` | Lint aprovado; 49 testes, 0 falhas |
| WSL, PHP 8.3.6 | `php examples/synthetic.php` | Aprovado |
| WSL, PHP 8.3.6 | PHP → ponte Rust TCP → `.so` C sintética | Aprovado |

A integração nativa cobre autenticação, configuração sintética, encoding Windows-1252, eco dos bytes originais, coleta interativa, retorno void de finalização, domínios auxiliares e retorno negativo desconhecido. A fixture TCP verifica também que a ponte removeu a nonce do ambiente antes de carregar a biblioteca.

A suíte cobre falhas de armazenamento antes/depois da FFI, intenção completa antes de finalizar múltiplas datas, recuperação seletiva, recibos e assinatura, NFPAG externo com hash e escopo, bloqueio de reentrância, precisão/formato, restrições de produto e descarte de dados sensíveis após encerramento. Testes no Windows precisam de um ambiente que permita criar o subprocesso e conectar o socket loopback local.

Contrato semântico utilizado:

- `protocol/fields.json`: SHA-256 `d50aa4656efc1f0ea458c153cc064310230007479cdd789b373415aa8c485a68`.
- `protocol/functions.json`: SHA-256 `ec2988b6cd1ead2d4b944fe1690d5ac93cdad6f8417d5d12260119d1df0a06e2`.

O runtime de produção continua Windows x64. O modo Linux existe para fixtures e não valida uma biblioteca fornecedora Linux. DLL, INI, PDFs, ZIPs e credenciais do fornecedor não integram a distribuição. Impressão física, assinatura, produto/convênio e recuperação externa ainda precisam de testes e homologação com o integrador.
