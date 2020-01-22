all: help

help:
	@echo "make migrate   -- update database structure"
	@echo "make release   -- push to repositories"
	@echo "make sql       -- open database console"
	@echo "make test      -- test syntax with phpcs"

migrate:
	phinx migrate

release:
	-hg push default
	-hg push github

sql:
	sqlite3 -header data/database.sqlite3

test: test-syntax

test-syntax:
	vendor/bin/phpcs --standard=PSR12 --ignore='database/migrations,src/Ufw1/compress.php' --exclude=Generic.Files.LineLength src

phpcbf:
	vendor/bin/phpcbf --standard=PSR12 --ignore='database/migrations' --exclude=Generic.Files.LineLength src
