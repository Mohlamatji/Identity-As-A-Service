<?php
/**
 * If you're running this via `php -S localhost:8000 -t public`, you'll
 * never hit this file - the built-in server's document root is already
 * `public/`. This file only matters for Apache/XAMPP-style deployments
 * where the whole project (including this file) gets dropped into htdocs,
 * e.g. htdocs/identity-vault/. It sends the browser into the real app
 * entry point at public/, using a relative redirect so it works no matter
 * what the project folder is named or how deep it sits in htdocs.
 */
header('Location: public/');
exit;
