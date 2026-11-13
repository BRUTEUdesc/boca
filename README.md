# BOCA UDESC — Nuances e Customizações

Este fork do BOCA contém modificações específicas para o ambiente da UDESC/Joinville. Esta página documenta comportamentos não óbvios que diferem do BOCA upstream.

---

## Instalação: `.env` e `composer install`

O `src/private/conf.php` lê os segredos de `/var/www/boca/.env` (fora do docroot, dono `root:www-data`, modo `640`) com o `vlucas/phpdotenv`. Depois de clonar em `/var/www/boca`:

```sh
cd /var/www/boca && composer install --no-dev   # usa o composer.json/composer.lock versionados
```

| Variável | Uso |
|---|---|
| `BOCA_DB_HOST` | host do PostgreSQL (padrão `localhost`; nos autojudges, o IP do servidor) |
| `BOCA_DB_PASS` | senha do `bocauser` |
| `BOCA_DB_SUPERPASS` | senha do superusuário do banco (só no servidor) |
| `BOCA_BASE_PASS` | senha inicial do `admin`/`system` de contest novo (sem ela: `boca`, como o upstream) |
| `BOCA_KEY` | chave secreta dos links de download e dos caches (só no servidor) |
| `BOCA_AUTOJUDGE_IP` | identificador do autojudge (`aj1`, `aj2`...; só nos AJs) |

Sem `.env` (ou sem `vendor/`) as páginas não dão fatal: a conexão com o banco falha e o BOCA mostra o erro.

---

## Usuários `teamext` e `teamonline`

Usuários cujo username começa com `teamext` ou `teamonline` são **filtrados automaticamente** das filas de tasks no painel admin (`src/admin/task.php`) e staff (`src/staff/task.php`) e não contam para o FIRST SOLVE dos balões (`DBFirstSolveTaskKeys` em `src/ftask.php`). Eles existem no sistema mas não aparecem nessas views.

Se um novo `task.php` for criado para outro papel, aplicar o mesmo filtro:

```php
$filtered_tasks = array();
for ($i = 0; $i < count($task); $i++) {
    if (strpos($task[$i]["username"], "teamext") !== 0 && strpos($task[$i]["username"], "teamonline") !== 0) {
        $filtered_tasks[] = $task[$i];
    }
}
$task = $filtered_tasks;
```

---

## Contest PDF bilíngue (PT + EN)

O sistema suporta dois PDFs de contest independentes:

| Arquivo | Path no servidor |
|---|---|
| Português | `src/private/secretcontest/<contest>/contest.pdf` |
| Inglês | `src/private/secretcontest/<contest>/contest-en.pdf` |

- Upload e delete de cada um são independentes em `src/admin/contest.php`
- Download PT: `src/downloadcontest.php`
- Download EN: `src/downloadencontest.php`
- Os links aparecem em `src/admin/problem.php` e `src/team/problem.php` condicionalmente (só se o arquivo existir)
- O upload só aceita arquivo que comece com `%PDF`. Antes do início da prova o download para times responde 404 ("not available yet"), sem deslogar
- O diretório `src/private/secretcontest/` é ignorado pelo git — os arquivos devem ser carregados via painel admin

---

## Animeitor — controle e status

### Comandos (start / stop / restart / clean cache)

Os comandos são **fire-and-forget**: rodam via `shell_exec` com `> /dev/null 2>&1 &`, retornando imediatamente sem bloquear a página. Não há detecção de erro do processo em si — o log registra apenas que o comando foi despachado.

Scripts envolvidos:
- `/usr/local/bin/animeitor-wrapper.sh` (via `sudo`, configurado no servidor)
- `/usr/local/bin/clean-webcast-cache.sh` (sem `sudo`: roda como www-data, dono do cache; chamado como root, ele mesmo troca para www-data). Também roda depois do "Set Contest", que só aceita contest existente.

Fotos e sons só podem ser enviados/apagados para logins de time do contest (nunca `fake.webp`/`applause.mp3`).

`admin/report/webcast.php` e `s4ris.php` (placar sem congelamento, sem login) só respondem para o próprio servidor e para a rede docker do animeitor (`deploy/apache/boca.conf`); nas outras páginas de `admin/report/`, `webcastcode` não dispensa o login.

### Status (polling assíncrono)

O status do animeitor **não é verificado no carregamento da página**. Em vez disso, `src/admin/animeitor.php` faz fetch para `src/admin/animeitor-status.php` a cada 5 segundos via JavaScript. O endpoint retorna JSON `{"status": "Running"|"Stopped", "color": "green"|"red"}` e requer sessão de admin.

---

## `ANIMEITOR_CONTEST` — contest ativo do webcast

O contest exibido pelo webcast (`src/admin/report/webcast.php`) vem de `src/private/animeitor.contest` (fora do git), gravado pelo painel admin do animeitor (`src/admin/animeitor.php`). Sem o arquivo, usa o contest ativo.

---

## `score.sep` e `webcast.sep`

Esses arquivos ficam fora do git (editados em `src/admin/scores.php`); os modelos versionados são `score.sep.sample` e `webcast.sep.sample`. Definem a configuração de categorias/faixas do contest.

### `src/private/score.sep`

Define as categorias de pontuação. Formato: `NomeCategoria startsite/endsite/step`

```
Oficial 100/299/1
CCL 300/399/1
Global 100/499/1
```

As faixas podem se sobrepor intencionalmente (ex: Global cobre Oficial e CCL).

### `src/private/webcast.sep`

Define o intervalo de sites do webcast. Formato: `categoria siteid/startsite/endsite`

```
global 1/100/499
```
