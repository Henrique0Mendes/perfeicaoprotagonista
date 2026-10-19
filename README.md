# Perfeição Protagonista — site e gestão de frota

Site de apresentação responsivo em HTML/CSS/JavaScript e área de gestão em PHP ligada a MySQL.

## Executar localmente

Requisitos: PHP 8.1 ou superior com `PDO` e `pdo_mysql`, e um servidor MySQL (por exemplo, XAMPP com Apache e MySQL/MariaDB).

1. Inicie o MySQL no XAMPP e abra o phpMyAdmin em `http://localhost/phpmyadmin`.
2. No separador SQL, execute:

   ```sql
   CREATE DATABASE perfeicao_protagonista
     CHARACTER SET utf8mb4
     COLLATE utf8mb4_unicode_ci;
   ```

3. Nesta pasta, copie `db-config.example.php` para `db-config.php` e ajuste host, porta, nome da base de dados, utilizador e palavra-passe de acordo com o seu MySQL. O ficheiro `db-config.php` é ignorado pelo Git para não publicar credenciais.
4. Confirme que `pdo_mysql` aparece ao executar `php -m`. No Windows, se não aparecer, ative `extension=pdo_mysql` no `php.ini` usado pelo comando `php` e reinicie o terminal.
5. Abra um terminal nesta pasta e inicie o site:

   ```powershell
   php -S 127.0.0.1:8000 router.php
   ```

6. Visite `http://127.0.0.1:8000/install.php`. O instalador cria as tabelas e a primeira conta de administrador.
7. Inicie sessão em `http://127.0.0.1:8000/login.php`.

### Importar cartões da API BP Frota

1. Peça credenciais de API no portal [bp Open Fleet](https://developer.fleet.bp.com/DE/getting-started). O acesso aos dados reais requer autorização da BP; as credenciais sandbox servem para experimentar.
2. Copie `bp-api-config.example.php` para `bp-api-config.php` e preencha `client_id` e `client_secret`. Escolha `sandbox` ou `production` conforme as credenciais emitidas. Pode preencher `authority_ids` e `parent_ids` se a BP lhe tiver indicado esses filtros.
3. Confirme que a extensão cURL está ativa no PHP.
4. Inicie sessão como administrador, abra **Cartões** e use **Importar da BP**. Escolha a data inicial das alterações a importar. A API limita cada pesquisa a uma janela de 90 dias.

A integração autentica no endpoint de Fleet Authentication e consulta a Card Management API (`GET /cards`). Os cartões são guardados em `fleet_cards` usando o número completo devolvido pela BP (ou o número de série quando o número completo não é fornecido). A operação é repetível: não duplica referências existentes e preserva as atribuições aos condutores. A API devolve os cartões alterados no período selecionado, pelo que a importação inicial abrange no máximo 90 dias; para manter a lista atualizada, volte a importar periodicamente usando uma data sobreposta. As credenciais ficam no ficheiro local ignorado pelo Git e nunca são enviadas ao navegador.

O instalador cria as tabelas e a primeira conta de administrador. Só administradores acedem ao painel de controlo, à gestão da frota e à criação de rotas e condutores. Cada condutor entra numa página pessoal que mostra apenas as rotas atribuídas à sua conta e permite registar uma jornada por dia, anexar uma foto obrigatória do PDA e alterar os dados apenas no próprio dia. Os condutores podem também carregar uma foto de perfil opcional no avatar do cabeçalho. O painel de administração mostra gráficos mensais de entregas e da leitura mais recente dos odómetros por viatura. A leitura do odómetro não equivale à distância percorrida no mês. As rotas usam os campos Empresa (CTT, CORREOS EXPRESS, DPD ou NACEX), Local, Condutor, Data de início e Viatura. Ao atualizar uma instalação anterior, volte a abrir `install.php` para aplicar as alterações à base de dados; as rotas antigas são preservadas e convertidas para os novos campos.

## Dados e publicação

A aplicação liga-se à base de dados MySQL configurada em `db-config.php`. Em produção, use um utilizador MySQL dedicado com permissões apenas para esta base de dados, HTTPS e cópias de segurança. O servidor PHP integrado serve apenas para desenvolvimento, não para alojar publicamente o site.

O nome da empresa já está definido como “Perfeição Protagonista”. O email de contacto (perfeicaoprotagonista@hotmail.com), os textos e os serviços são exemplos editáveis. A área reservada organiza a administração em páginas próprias: a visão geral, a gestão conjunta de rotas e condutores, as jornadas dos condutores, os abastecimentos (litros, custos e gráficos), a frota e os cartões. As contas podem ter o perfil de administrador, condutor ou ambos. Os condutores registam abastecimentos na área pessoal; os administradores consultam o histórico e os gráficos em Abastecimentos. Na página de rotas, os administradores planeiam rotas e atribuem condutores e viaturas existentes; o registo de viaturas é feito na secção Frotas. As viaturas registam motorização a gasóleo ou elétrica e indicam se são alugadas; o regime pode ser alterado diretamente na tabela de frota. Os cartões têm apenas número e empresa BP; só administradores podem criar, atribuir ou remover cartões, e cada condutor vê apenas os que lhe foram atribuídos. O formulário de contacto abre o programa de email e não envia dados para uma base de dados.



