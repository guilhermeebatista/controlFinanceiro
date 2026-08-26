#!/usr/bin/env bash
#
# Remove TODA planilha do historico do git.
#
# Por que existe: o commit de limpeza tirou os arquivos da arvore, mas nao do
# historico. Ate rodar isto, qualquer pessoa com acesso ao repositorio recupera
# os arquivos com um `git show` no commit de origem — e a planilha original
# esta la desde o primeiro commit do projeto.
#
# Remove por EXTENSAO (.xlsx/.xlsm/.xls), nao por lista de nomes. Uma versao
# anterior usava lista fixa e deixou passar um backup cujo nome fugia do
# padrao; a varredura de verificacao pegou. Extensao nao tem esse furo.
#
# Pode ser rodado mais de uma vez com seguranca: detecta se o repositorio ja
# foi reescrito e, nesse caso, NAO refaz o fetch — refazer traria o historico
# antigo, com os arquivos, de volta.
#
# NAO rode sem ler. Reescreve o historico: todo commit muda de SHA e o push
# seguinte e um force push.
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO"

FILTER_REPO="${FILTER_REPO:-$REPO/.git/filter-repo-bin}"
REMOTE_URL="${REMOTE_URL:-git@github.com:guilhermeebatista/controlFinanceiro.git}"
LIMPEZA="limpeza-para-publicacao"

# filter-repo deixa esta marca depois de rodar.
JA_REESCRITO=0
[ -e "$REPO/.git/filter-repo/already_ran" ] && JA_REESCRITO=1

echo "==> 1/7  Conferindo que a arvore esta limpa"
if [ -n "$(git status --porcelain)" ]; then
  echo "ERRO: ha mudancas nao commitadas. Commite ou guarde antes de reescrever." >&2
  exit 1
fi

echo "==> 2/7  Backup (bundle com todas as refs)"
BK="$REPO/../BACKUP-antes-da-reescrita-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BK"
git bundle create "$BK/repo-completo.bundle" --all
find . -maxdepth 1 \( -iname '*.xlsx' -o -iname '*.xlsm' -o -iname '*.xls' \) \
  -exec cp -a {} "$BK/" \; 2>/dev/null || true
echo "    backup em: $BK"

echo "==> 3/7  Baixando git-filter-repo, se preciso"
if [ ! -x "$FILTER_REPO" ]; then
  curl -sSL -o "$FILTER_REPO" \
    https://raw.githubusercontent.com/newren/git-filter-repo/main/git-filter-repo
  chmod +x "$FILTER_REPO"
fi

echo "==> 4/7  Branches locais"
if [ "$JA_REESCRITO" -eq 1 ]; then
  echo "    repositorio JA foi reescrito antes; pulando o fetch de proposito."
  echo "    (buscar do remoto agora traria de volta o historico antigo, com"
  echo "     os arquivos que acabamos de remover)"
  git branch -v
else
  # filter-repo so reescreve o que estiver em refs/heads: uma branch que exista
  # apenas no remoto seria perdida, entao materializamos todas localmente.
  git fetch origin --prune
  ATUAL="$(git branch --show-current)"
  for b in $(git for-each-ref --format='%(refname:strip=3)' refs/remotes/origin | grep -v '^HEAD$'); do
    if [ "$b" = "$ATUAL" ]; then
      echo "    $b: em uso pelo worktree, mantida como esta"
      continue
    fi
    git branch --force "$b" "origin/$b"
  done
  git branch -v
fi

echo "==> 4b/7 Levando a limpeza para o main"
# Sem isto, o main reescrito sairia sem os commits de limpeza e o repositorio
# continuaria impublicavel. So avanca se for fast-forward.
if git rev-parse --verify --quiet "$LIMPEZA" >/dev/null; then
  if git merge-base --is-ancestor main "$LIMPEZA"; then
    git branch --force main "$LIMPEZA"
    echo "    main em $(git rev-parse --short main)"
  else
    echo "    ATENCAO: $LIMPEZA nao e descendente de main. Resolva o merge" >&2
    echo "    manualmente antes de reescrever." >&2
    exit 1
  fi
else
  echo "    branch $LIMPEZA nao existe; assumindo que a limpeza ja esta no main"
fi

echo "==> 5/7  Reescrevendo o historico (removendo toda planilha)"
python3 "$FILTER_REPO" --force --filename-callback '
import os
ext = os.path.splitext(filename.decode("utf-8", "surrogateescape"))[1].lower()
return None if ext in (".xlsx", ".xlsm", ".xls") else filename
'

echo "==> 6/7  Verificando que NENHUMA planilha sobrou em NENHUMA ref"
SOBRAS=$(git rev-list --objects --all \
  | git cat-file --batch-check='%(objecttype) %(objectname) %(rest)' \
  | awk '$1=="blob" && $3!="" {print $3}' \
  | grep -iE '\.(xlsx|xlsm|xls)$' | sort -u || true)
if [ -n "$SOBRAS" ]; then
  echo "    AINDA HA PLANILHAS NO HISTORICO:" >&2
  echo "$SOBRAS" | sed 's/^/      /' >&2
  echo "ERRO: a limpeza nao ficou completa. NAO faca push." >&2
  exit 1
fi
echo "    nenhuma planilha em nenhum commit de nenhuma branch"

# Conferencia extra: o e-mail pessoal tambem nao deve estar em lugar nenhum.
if git grep -qI 'REDIGIDO' $(git rev-list --all) -- 2>/dev/null; then
  echo "    AVISO: o e-mail pessoal ainda aparece em algum commit do historico." >&2
  echo "    Isso nao bloqueia a publicacao, mas considere um --replace-text." >&2
else
  echo "    e-mail pessoal tambem nao aparece no historico"
fi

echo "==> 7/7  Restaurando o remote (NADA foi enviado ainda)"
git remote get-url origin >/dev/null 2>&1 || git remote add origin "$REMOTE_URL"
git remote -v

cat <<'FIM'

    Historico local limpo. Nada foi enviado ao GitHub.

    Confira o que quiser (git log, git show) e, quando estiver satisfeito:

        git push --force --all origin
        git push --force --tags origin

    O que o force push NAO resolve sozinho:

    1. Outros clones desta maquina (ex.: ~/projects/bills) continuam com o
       historico antigo. Em cada um:
           git fetch origin && git reset --hard origin/main

    2. Pull requests ja abertos no GitHub guardam os commits antigos e
       continuam acessiveis pela interface. Feche-os e apague as branches.

    3. O GitHub mantem objetos orfaos em cache por um tempo. Se o repositorio
       ja tiver sido publico, peca a limpeza ao suporte.

    4. Se o repositorio ja foi publico alguma vez, trate os dados dessas
       planilhas como expostos, independentemente desta limpeza.

    5. O stash local ainda guarda um .env antigo:  git stash drop

FIM
