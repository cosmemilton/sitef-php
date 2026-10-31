# Cobertura semântica e limites de integração

O motor consome `protocol/fields.json` e `protocol/functions.json`, compartilhados com Node/Python. São definições para 444 linhas da tabela-base VRS258, 64 eventos e 28 campos suplementares, além de 131 funções. Essa contagem descreve o contrato documental; não representa 536 produtos homologados ou formatos proprietários inventados pelo SDK.

| Área | Comportamento implementado | O que a aplicação fornece |
| --- | --- | --- |
| Transporte | NDJSON autenticado por nonce, loopback efêmero, subprocesso sem shell, limites, IDs, EOF e prazo | Ponte própria confiável, DLL externa Windows x64 e ambiente SiTef |
| Retornos 5.2.1 | Códigos conhecidos e desconhecidos preservados; domínios de contagem/pinpad/barcode separados | Apresentação da falha e resolução operacional, sem repetir venda automaticamente |
| Funções 5.2.2 | Política financeira conhecida inferida; divergência rejeitada; funções ambíguas exigem declaração explícita | Política de extensão desconhecida e critérios de homologação do produto |
| TiposCampo 5.3.2 | Definição/contexto, tipos, formatos, ocorrências ordenadas e dados brutos preservados | `resolveField` para estrutura, escala ou codificação que o documento deixa a cargo do produto |
| Dicas/precisões | 2355 e 2470 na próxima coleta; 1121/1126 no contexto de produto; nenhuma conversão por float | Restrições adicionais de charset/produto não definidas na referência |
| Coleta | Comprimento em bytes da codificação nativa, data, enum, decimal, menu, cheque e barcode; confirmação de Mc/10000 explícita | Interface própria ou modal frontend; dados de sistema sem operador no comando29 |
| Contexto seguro | Família 205x depende do contexto real da rotina de leitura, sem confundir data/menu com hash | Tratamento de dados protegidos pelo produto via resolvedor |
| Comprovantes | Cada ocorrência tem ID/hash. CB/admin aguardam impressão e armazenamento no laço; diferidos aguardam antes da confirmação | `storeReceipt`, `printReceipt`, `loadReceipt`; reconhecimento explícito no modo explicit-return |
| Eventos | Recursos 5042/5043 com limpeza; 5074 depois de imprimir e antes da confirmação; 5010 é só notificação | Hooks de lock/unlock, assinatura e diagnóstico |
| Saldo/troco | Textos 109/137/138/1319 sem unidade presumida; aritmética exata quando há formato explícito | `monetaryFormats` ou resolvedor `{unscaled,scale}` e validação da compra |
| Produtos/PBM | Campos tipados em assessment, medicamentos por1011, comparações inequívocas e estados satisfied/violated/unresolved | Escalas/contexto ausentes e `validateTransaction`; uma violação inequívoca não é apagada por true |
| Corban | Blocos por607, inclusive índice repetido, conservam sequência e comprovantes | Impressora e validações específicas do convênio |
| NFPAG | 730/731/732, restrição13, origem de cheque, dados obrigatórios, soma exata e escopo de todo cupom/data | Métodos e total explícitos; armazenamento externo por hash do payload privado |
| Múltiplos pagamentos | Append explícito, guarda de cupom/data, virada de data, plano completo antes da primeira FFI e finalização por partição | Decisão da compra fiscal concluída ou desfeita |
| Seleção | Índice161 único, rede resolvida, APIs tipadas e rejeição de seletor bruto | Fonte131/158 ou provider inequívoco de rede |
| Recuperação | Consulta130/131 sem alterar journal, plano seletivo conservado, hash de recibo/payload, V1→V2 | Reconciliação externa, stores persistentes e atestado de obrigações ausentes no V1 |

`assessment.fields` contém a evidência tipada de cada ocorrência. `assessment.productConstraints` distingue regra satisfeita, violação comprovada e interpretação não determinada. Campos cujo contrato é aberto não viram sucesso silencioso: o resolvedor/validador apropriado precisa assumir aquela interpretação explicitamente.

Um retorno final0 continua significando conclusão nativa conhecida. Se um hook/validador falhar depois desse retorno, o pagamento fica aguardando decisão com confirmação bloqueada, permitindo desfazer conforme o escopo persistido. Não há recusa fictícia para esconder uma aprovação.

O SDK não fornece hardware, impressão física universal, credenciamento, regras privadas não documentadas nem homologação. Os hooks são integrações reais da aplicação, não callbacks que devam retornar true sem executar a obrigação. Os testes usam somente fixtures próprias; nenhum fluxo financeiro real foi executado para comprovar este componente.
