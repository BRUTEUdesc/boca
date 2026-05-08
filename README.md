# BOCA UDESC — Nuances e Customizações

Este fork do BOCA contém modificações específicas para o ambiente da UDESC/Joinville. Esta página documenta comportamentos não óbvios que diferem do BOCA upstream.

---

## Usuários `teamext`

Usuários cujo username começa com `teamext` são **filtrados automaticamente** das filas de tasks no painel admin (`src/admin/task.php`) e staff (`src/staff/task.php`). Eles existem no sistema mas não aparecem nessas views.

Se um novo `task.php` for criado para outro papel, aplicar o mesmo filtro:

```php
$filtered_tasks = array();
for ($i = 0; $i < count($task); $i++) {
    if (strpos($task[$i]["username"], "teamext") !== 0) {
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
| Português | `src/private/secretcontest/contest.pdf` |
| Inglês | `src/private/secretcontest/contest-en.pdf` |

- Upload e delete de cada um são independentes em `src/admin/contest.php`
- Download PT: `src/downloadcontest.php`
- Download EN: `src/downloadencontest.php`
- Os links aparecem em `src/admin/problem.php` e `src/team/problem.php` condicionalmente (só se o arquivo existir)
- O diretório `src/private/secretcontest/*.pdf` é ignorado pelo git — os arquivos devem ser carregados via painel admin

---

## Animeitor — controle e status

### Comandos (start / stop / restart / clean cache)

Os comandos são **fire-and-forget**: rodam via `shell_exec` com `> /dev/null 2>&1 &`, retornando imediatamente sem bloquear a página. Não há detecção de erro do processo em si — o log registra apenas que o comando foi despachado.

Scripts envolvidos (precisam de `sudo` configurado no servidor):
- `/usr/local/bin/animeitor-wrapper.sh`
- `/usr/local/bin/clean-webcast-cache.sh`

### Status (polling assíncrono)

O status do animeitor **não é verificado no carregamento da página**. Em vez disso, `src/admin/animeitor.php` faz fetch para `src/admin/animeitor-status.php` a cada 5 segundos via JavaScript. O endpoint retorna JSON `{"status": "Running"|"Stopped", "color": "green"|"red"}` e requer sessão de admin.

---

## `ANIMEITOR_CONTEST` — número de contest hardcoded

Em `src/admin/report/webcast.php`:

```php
$ANIMEITOR_CONTEST = 7;
```

Este valor é **hardcoded** e deve ser atualizado manualmente quando o contest do webcast mudar. A alternativa baseada em sessão (`$_SESSION["usertable"]["contestnumber"]`) está comentada na linha seguinte.

---

## `score.sep` e `webcast.sep`

Esses arquivos são versionados e definem a configuração de categorias/faixas do contest.

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
