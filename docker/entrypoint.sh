#!/bin/sh
# Prepara o banco e sobe o Apache.
#
# O schema em si vem do database.sql, executado pelo próprio container do MySQL
# na primeira subida. O migrate cuida do que depende da aplicação: garantir a
# conta 'planilha' com o seed, promover o primeiro admin e aplicar ajustes de
# schema em bancos já existentes.
set -e

php /var/www/html/bin/migrate.php || echo "[entrypoint] migrate falhou; a aplicação sobe assim mesmo" >&2

exec apache2-foreground
