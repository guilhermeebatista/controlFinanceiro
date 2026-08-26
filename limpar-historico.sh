#!/usr/bin/env bash
#
# Remove as planilhas com dados financeiros reais de TODO o historico do git.
#
# Por que isso e necessario: o commit de limpeza tirou os arquivos da arvore,
# mas nao do historico. Ate rodar isto, qualquer pessoa com acesso ao
# repositorio recupera os arquivos com um `git show` no commit de origem —
# e o .xlsm esta la desde o primeiro commit do projeto.
#
# NAO rode sem ler. Ele reescreve o historico: todo commit muda de SHA e o
# push seguinte e um force push.
#
# Uso:  bash limpar-historico.sh
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO"

FILTER_REPO="${FILTER_REPO:-$REPO/.git/filter-repo-bin}"

ALVOS=(
  "Controle Financ. Pessoal 6.0 - R00.xlsm"
  "backup_guilherme_2026-07-08.xlsx"
  "backup_guilherme_2026-07-09.xlsx"
)

echo "==> 1/7  Conferindo que a arvore esta limpa"
if [ -n "$(git status --porcelain)" ]; then
  echo "ERRO: ha mudancas nao commitadas. Commite ou guarde antes de reescrever." >&2
  exit 1
fi

echo "==> 2/7  Backup (bundle com todas as refs)"
BK="$REPO/../BACKUP-antes-da-reescrita-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BK"
git bundle create "$BK/repo-completo.bundle" --all
for f in "${ALVOS[@]}"; do [ -e "$f" ] && cp -a "$f" "$BK/" || true; done
echo "    backup em: $BK"

echo "==> 3/7  Baixando git-filter-repo, se preciso"
if [ ! -x "$FILTER_REPO" ]; then
  curl -sSL -o "$FILTER_REPO" \
    https://raw.githubusercontent.com/newren/git-filter-repo/main/git-filter-repo
  chmod +x "$FILTER_REPO"
fi

echo "==> 4/7  Garantindo uma branch local para cada branch do remoto"
git fetch origin --prune
for b in $(git for-each-ref --format='%(refname:strip=3)' refs/remotes/origin | grep -v '^HEAD$'); do
  git branch --force "$b" "origin/$b"
done
git branch -v

echo "==> 5/7  Reescrevendo o historico"
ARGS=()
for f in "${ALVOS[@]}"; do ARGS+=(--path "$f"); done
python3 "$FILTER_REPO" --invert-paths "${ARGS[@]}" --force

echo "==> 6/7  Verificando que os arquivos sumiram de TODO o historico"
FALHOU=0
for f in "${ALVOS[@]}"; do
  N=$(git log --all --oneline -- "$f" | wc -l)
  if [ "$N" -ne 0 ]; then echo "    AINDA PRESENTE: $f ($N commits)"; FALHOU=1
  else echo "    limpo: $f"; fi
done
# varredura por extensao, para pegar qualquer planilha fora da lista acima
SOBRAS=$(git rev-list --objects --all \
  | git cat-file --batch-check='%(objecttype) %(objectname) %(rest)' \
  | awk '$1=="blob"{print $3}' | grep -iE '\.xlsx?$|\.xlsm$' || true)
if [ -n "$SOBRAS" ]; then echo "    AINDA HA PLANILHAS NO HISTORICO:"; echo "$SOBRAS"; FALHOU=1; fi
[ "$FALHOU" -eq 0 ] || { echo "ERRO: a limpeza nao ficou completa. Nao faca push." >&2; exit 1; }

echo "==> 7/7  Pronto para o force push (NADA foi enviado ainda)"
cat <<'FIM'

    O historico local esta limpo. Nada foi enviado ao GitHub.

    Confira o que quiser e, quando estiver satisfeito:

        git remote add origin git@github.com:guilhermeebatista/controlFinanceiro.git
        git push --force --all origin
        git push --force --tags origin

    Atencao ao que o force push NAO resolve sozinho:

    1. Outros clones desta maquina (ex.: ~/projects/bills) continuam com o
       historico antigo. Em cada um:
           git fetch origin && git reset --hard origin/main

    2. Pull requests ja abertos no GitHub guardam os commits antigos e
       continuam acessiveis. Feche e apague as branches de PR antigas.

    3. O GitHub mantem os objetos orfaos em cache por um tempo. Se o
       repositorio ja tiver sido publico em algum momento, peca ao suporte
       a limpeza (Settings > pedir "garbage collection" via support).

    4. Trate os dados dessas planilhas como ja expostos se o repositorio
       tiver sido publico antes.

FIM
