# Test di Spoome

Stack dev-only (nessuna dipendenza a runtime: il server usa `src/autoload.php`).

## Setup (macchina di sviluppo)
```
composer install            # installa phpunit/phpstan/php-cs-fixer in vendor/ (dev)
composer test               # esegue PHPUnit
composer stan               # PHPStan livello 5 su src/
composer cs                 # php-cs-fixer (dry-run)
```

## Suite
- `tests/Unit` — logica pura, nessun DB: password policy, `Str::handle` (hyphen-friendly),
  `ServiceResult`. Eseguibili ovunque.
- `tests/Integration` — richiedono un MySQL usa-e-getta via env, altrimenti si SKIPPANO:
  ```
  export SPOOME_TEST_DSN="mysql:host=127.0.0.1;dbname=spoome_test;charset=utf8mb4"
  export SPOOME_TEST_USER=root SPOOME_TEST_PASS=secret
  composer test
  ```
  NON usano mai il DB di produzione.

## Deploy
`composer install --no-dev` in produzione (o non deployare `vendor/`): il runtime non dipende da Composer.
Aree critiche da coprire prossimamente: Claims ownership (assignOwner + ricontrolli race in approve),
consumo atomico reset password, `ProfileRepository::listPublic` (binding/FULLTEXT).
