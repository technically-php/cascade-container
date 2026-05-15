.PHONY: tests

tests: vendor
	php vendor/bin/pest

vendor: composer.json
	composer install
