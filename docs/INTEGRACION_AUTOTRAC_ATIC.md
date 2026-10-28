# Integração Autotrac ATIC

## Configuração por empresa

O acesso é configurado em **Administração → Integrações → Telemetria da empresa**. Selecione **Autotrac (ATIC API)** e informe a chave de assinatura, o usuário e a senha do usuário com permissão para a ATIC API. Use **Testar conexão ATIC**, selecione a conta devolvida pelo serviço e salve.

As credenciais pertencem à empresa e são cifradas na coluna `token_cifrado` de `integraciones_telemetria`, usando a chave de criptografia da aplicação. Não guardar credenciais ATIC no `.env`, no código ou em logs. Cada empresa configura a própria conta. O timeout HTTP pode ser ajustado com `AUTOTRAC_TIMEOUT_SECONDS` (padrão: 30 segundos).

Quando a ATIC rejeita uma chamada, a tela informa o status HTTP e, se a resposta trouxer campos JSON de erro reconhecidos, mostra um detalhe limitado. A chave, o usuário e a senha são redigidos antes de exibir esse detalhe; o corpo bruto da resposta não é registrado.

## Vínculo dos veículos

Depois de salvar, abra **Vincular equipos**. O sistema consulta os veículos acessíveis pela conta Autotrac e permite associá-los aos equipamentos cadastrados na empresa. A associação é explícita e limitada à empresa autenticada. Se as credenciais forem trocadas por outra conta, os vínculos cujos códigos de veículo deixaram de estar disponíveis são inativados.

## Atualização dos dados

O botão de atualização de telemetria existente consulta as integrações ativas e grava a posição mais recente e os dados disponíveis no histórico de amostras/instantâneas. A integração não cria registros em `lecturas_equipo` e não altera quilometragem, horímetro ou planos preventivos. Valores de odômetro/horímetro iguais a zero são tratados como ausentes. O odômetro recebido no endpoint de posições é convertido de centésimos de quilômetro; valores de horímetro são armazenados em décimos de hora.

O catálogo de unidades é consultado pelo endpoint `/v1/accounts/{accountCode}/vehicles`; a captura consulta `/positions` e `/expandedalerts` por veículo. O adaptador usa TLS e timeout, não segue redirecionamentos e não registra chaves nem senhas.

## Operação e limites

A captura usa o fluxo de atualização manual já existente. Não há agendamento periódico novo. Cada atualização faz consultas por veículo; para frotas grandes, confirmar com a Autotrac os limites de chamadas aplicáveis antes de habilitar uma frequência automática. Dados que não são reconhecidos como posição, hodômetro, horímetro, ignição, RPM ou temperatura não são interpretados como leituras de manutenção.
