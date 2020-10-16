FROM php:7.4.3-cli-alpine
MAINTAINER Shane Mc Cormack <dataforce@dataforce.org.uk>

WORKDIR /app

COPY . /app

RUN \
  apk update && apk add git unzip curl wget docker openssh && \
  ln -s /usr/local/bin/php /usr/bin/php && \
  docker-php-source extract && \
  docker-php-ext-install pcntl && \
  docker-php-ext-install sockets && \
  docker-php-source delete && \
  curl -sS https://getcomposer.org/installer | php -- --no-ansi --install-dir=/usr/bin --filename=composer && \
  composer install

EXPOSE 8010

ENTRYPOINT ["/app/run.php"]
