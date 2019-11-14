release:
	hg push default
	hg push github

test: test-syntax

test-syntax:
	vendor/bin/phpcs src
