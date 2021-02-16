FROM php:7.4.15-fpm-alpine3.13

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS redis curl curl-dev imagemagick imagemagick-dev composer nginx libwebp-tools
RUN docker-php-ext-install curl
RUN yes | pecl install imagick
RUN docker-php-ext-enable imagick
WORKDIR /var/www/optimizer
COPY ./ /var/www/optimizer
COPY ./docker/conf/nginx.conf /etc/nginx/conf.d/default.conf

RUN composer install

RUN openssl req -x509 -nodes -subj "/C=DE/ST=Bremen/L=Bremen/O=IT/CN=optimizer.standalone" -days 3650 -newkey rsa:2048 -keyout /etc/nginx/nginx.key -out /etc/nginx/nginx.crt

COPY ./docker/conf/fpm-conf.conf /php-fpm.conf
RUN mkdir /run/nginx && touch /run/nginx/nginx.pid && chown nginx /run/nginx/nginx.pid && mkdir cache && chown nginx cache

CMD env && sed -i "s#DUMMYTARGET#$TARGET#" /etc/nginx/conf.d/default.conf && cat /etc/nginx/conf.d/default.conf && redis-server & php-fpm --fpm-config /php-fpm.conf -c /usr/local/etc/php/php.ini -D && chown nginx:nginx /var/run/php-fpm.sock && nginx -g 'pid /run/nginx/nginx.pid;daemon off;' -c /etc/nginx/nginx.conf
