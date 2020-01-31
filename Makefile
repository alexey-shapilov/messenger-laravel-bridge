test:
	docker run --rm -v ${PWD}:/messenger --workdir=/messenger -u "$$(id -u):$$(id -g)" --net=clcorp-network clcorp/container:php-fpm php vendor/bin/phpunit -v --colors=always
test-debug:
	docker run --rm -v ${PWD}:/messenger --workdir=/messenger -e "XDEBUG_CONFIG=remote_host=172.17.0.1 remote_enable=1 remote_autostart=1 remote_port=21000" -e PHP_IDE_CONFIG=serverName=test -u "$$(id -u):$$(id -g)" --net=clcorp-network clcorp/container:php-fpm php vendor/bin/phpunit -v --colors=always
