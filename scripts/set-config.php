<?php

declare(strict_types=1);

/*
 * Set this extension's configuration in a TYPO3 settings.php, for verify.sh.
 *
 * ⚠️ The values arrive as SEPARATE argv entries, not as a JSON blob, on purpose.
 * The first version of this passed JSON through a shell string into `php -r`,
 * and the nested quoting silently ate the values — every case rendered as if no
 * domain were configured, which looked exactly like a broken extension. Same
 * family as a regex dying across YAML → sh → node: if a value has to survive
 * three quoting layers, remove the layers rather than escaping harder.
 *
 *   php scripts/set-config.php <settings.php> <domain> <companion> <api>
 */

if ($argc !== 5) {
    fwrite(STDERR, "usage: set-config.php <settings.php> <domain> <companion> <api>\n");
    exit(2);
}

[, $file, $domain, $companion, $api] = $argv;

if (!is_file($file)) {
    fwrite(STDERR, "REFUSING: no such settings file: $file\n");
    exit(1);
}

$config = require $file;
$config['EXTENSIONS']['pulse_analytics'] = [
    'domain' => $domain,
    'companion' => $companion,
    'api' => $api,
];

file_put_contents($file, '<?php' . PHP_EOL . 'return ' . var_export($config, true) . ';' . PHP_EOL);
