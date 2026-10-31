# Cobertura e trabalho pendente

O catálogo `protocol/catalog.json` é compartilhado com os clientes Node/Python e distingue dados documentados de implementação. A matriz do PDF x86 VRS258 contém 444 linhas em 5.3.2; esse número não representa 444 formatos de negócio implementados neste cliente. Nenhum PDF acompanha o projeto.

| Área | Implementado | Limite atual |
| --- | --- | --- |
| Transporte | NDJSON correlacionado, nonce de 256 bits, loopback 127.0.0.1, subprocesso sem shell, limites/prazos/EOF | Não é API de navegador; worker PHP deve persistir a sessão |
| Funções 5.2.2 | Códigos int32 abertos; catálogo comum cobre os 124 IDs da tabela e carteiras | Cada produto/função precisa da integração de negócio e homologação correspondentes |
| Retornos 5.2.1 | Conhecidos catalogados; qualquer positivo/negativo preservado; domínios auxiliares separados | A aplicação decide apresentação e ações operacionais; não há sangria ou repetição automática |
| TiposCampo 5.3.2 | Lista ordenada, repetições, bytes base64, eco do comando 0, callback e observador | Saldo/troco, PBM, documentos, produtos, grupos e regras fiscais não são inferidos |
| Comandos de coleta | Callback recebe comando/tipo/min/max/buffer; 29 não depende de tela; 23 não chama o callback de UI | O integrador precisa implementar todos os comandos/formatos que seu produto solicitar |
| Comprovantes 121/122 | Disponíveis imediatamente em onExchange e preservados no resultado | Armazenamento e impressão são da aplicação; CB/administrativas exigem impressão imediata e cancelamento se falhar |
| Eventos | Números e descrição no catálogo; onExchange recebe as trocas | Bloqueio/liberação 5042/5043 e assinatura em papel 5074 precisam de ações da aplicação |
| Finalização | Decisão explícita, IDs originais, journal antes da chamada void, retorno local honesto | Política automática por função ainda não implementada; consultas devem informar requiresFinalization false |
| Recuperação | Locks/journal, estado incerto, sem replay; decisão/escopo conservados | Consultas detalhadas 130/131 em uncertain ainda exigem reconciliação externa; só contagem auxiliar disponível |
| UI | Frontend pode consumir o modal Node por canal autenticado da aplicação | Core PHP não inclui modal, webserver ou endpoint pronto; não expõe o IPC de backend |

Prioridades de evolução: API obrigatória de impressão/armazenamento conforme modalidade; ações de eventos com obrigação; consulta detalhada de pendências sem sobrescrever journal financeiro; política por função; metadados de formato 2355/2470 e combustível; agrupamento de pagamentos/documentos; callbacks e testes específicos de cada produto. Antes de anunciar uma funcionalidade de rede como suportada, implementar seus documentos e critérios de homologação.
