all: help

help:
	@echo "make migrate   -- update database structure"
	@echo "make release   -- push to repositories"
	@echo "make sql       -- open database console"
	@echo "make test      -- test syntax with phpcs"

migrate:
	phinx migrate

push: release

release:
	-hg push default
	-hg push github

sql:
	sqlite3 -header data/database.sqlite3

test: test-syntax
	phpunit

test-syntax:
	find src -type f -name '*.php' -exec php -l {} \;
	vendor/bin/phpcs --standard=PSR12 --ignore='database/migrations,src/compress.php' --exclude=Generic.Files.LineLength src

phpcbf:
	vendor/bin/phpcbf --standard=PSR12 --ignore='database/migrations' --exclude=Generic.Files.LineLength src
