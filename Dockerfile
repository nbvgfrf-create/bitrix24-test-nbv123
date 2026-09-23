FROM php:8.3-cli

WORKDIR /app

COPY . /app

RUN mkdir -p /app/queues /app/logs /app/locks

EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /app"]