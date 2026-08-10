FROM php:8.3-apache

# pdo_mysql: acesso ao banco. zip: leitura/escrita das planilhas .xlsx/.xlsm
# (um .xlsx é um ZIP de XMLs). libzip-dev só é necessária para compilar a
# extensão; sai da imagem em seguida.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev \
 && docker-php-ext-install pdo_mysql zip \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers \
 && a2dismod -f autoindex

# DocumentRoot em public/: src/, resources/, bin/ e database.sql ficam fora
# da árvore servida pela web.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
      /etc/apache2/sites-available/*.conf \
      /etc/apache2/apache2.conf \
 && printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n\
    Options -Indexes -MultiViews\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
# Defesa em profundidade: nada dentro de static/ é interpretado como código.\n\
<Directory ${APACHE_DOCUMENT_ROOT}/static>\n\
    php_admin_flag engine off\n\
</Directory>\n' > /etc/apache2/conf-available/z-app.conf \
 && a2enconf z-app

# Não anunciar versão de PHP/Apache: poupa ao atacante o trabalho de descobrir
# contra qual CVE mirar.
RUN printf 'ServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' \
      > /etc/apache2/conf-available/z-hardening.conf \
 && a2enconf z-hardening

COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini

WORKDIR /var/www/html
COPY --chown=root:root public/    ./public/
COPY --chown=root:root src/       ./src/
COPY --chown=root:root resources/ ./resources/
COPY --chown=root:root bin/       ./bin/
COPY --chown=root:root tests/     ./tests/
COPY --chown=root:root migrations/ ./migrations/
COPY --chown=root:root database.sql ./database.sql
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# O processo do Apache (www-data) só lê o código; nada da aplicação é gravável
# a partir da web, então uma falha de escrita não vira execução de código.
RUN chmod +x /usr/local/bin/entrypoint.sh && chmod -R a-w /var/www/html

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
