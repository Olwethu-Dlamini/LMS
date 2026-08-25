# PHPMailer 6.9.3 (vendored)

Three source files copied from the upstream release, not installed by composer.

The project has no dependency manager and is deployed by copying the tree onto
the server, so an SMTP client that needs `composer install` first would add a
build step where there is none. PHPMailer supports use without composer, which
makes vendoring the honest option rather than a workaround: the code that runs
in production is the code in this directory, visible in the diff.

Only what sending needs is here. `OAuth.php`, `OAuthTokenProvider.php`,
`POP3.php` and `DSNConfigurator.php` are left out - the first two serve XOAUTH2
authentication, `POP3.php` reads mail rather than sending it, and the
configurator parses DSN strings we do not use.

Loaded by `helpers/Mailer.php`, which is the only file that should require these
directly.

## Updating

Take the same three files from a newer tag and replace them, then run the test
suite and `php tools/test_email.php`:

    curl -sSL -o /tmp/phpmailer.tar.gz \
      https://github.com/PHPMailer/PHPMailer/archive/refs/tags/vX.Y.Z.tar.gz
    tar xzf /tmp/phpmailer.tar.gz -C /tmp
    cp /tmp/PHPMailer-X.Y.Z/src/{PHPMailer,SMTP,Exception}.php lib/PHPMailer/
    cp /tmp/PHPMailer-X.Y.Z/VERSION lib/PHPMailer/VERSION

Security releases matter here: this library parses addresses and builds headers
from data the application supplies.

Upstream: https://github.com/PHPMailer/PHPMailer
Licence: LGPL-2.1 (see LICENSE)
