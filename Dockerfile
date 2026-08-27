FROM erseco/alpine-moodle:v5.2.1

USER root

RUN apk add --no-cache nano

COPY 50-moodle-runtime-config.sh /docker-entrypoint-init.d/50-moodle-runtime-config.sh

RUN chmod +x /docker-entrypoint-init.d/50-moodle-runtime-config.sh

# OPcache tuning
RUN printf '%s\n' \
'opcache.enable=1' \
'opcache.enable_cli=1' \
'opcache.memory_consumption=256' \
'opcache.interned_strings_buffer=32' \
'opcache.max_accelerated_files=50000' \
'opcache.validate_timestamps=1' \
'opcache.revalidate_freq=60' \
'opcache.save_comments=1' \
> /etc/php83/conf.d/99-moodle-opcache.ini

# PHP-FPM tuning
RUN sed -i \
    -e 's/^pm = .*/pm = dynamic/' \
    -e 's/^pm.max_children = .*/pm.max_children = 64/' \
    -e 's/^pm.max_requests = .*/pm.max_requests = 500/' \
    /etc/php83/php-fpm.d/www.conf \
 && sed -i '/^pm.start_servers =/d' /etc/php83/php-fpm.d/www.conf \
 && sed -i '/^pm.min_spare_servers =/d' /etc/php83/php-fpm.d/www.conf \
 && sed -i '/^pm.max_spare_servers =/d' /etc/php83/php-fpm.d/www.conf \
 && printf '%s\n' \
'pm.start_servers = 12' \
'pm.min_spare_servers = 8' \
'pm.max_spare_servers = 20' \
>> /etc/php83/php-fpm.d/www.conf