<?php
/**
 * Bootstrap for the unit tests of pure classes (no OJS needed): autoload the plugin's namespace.
 */
spl_autoload_register(function (string $class) {
    $prefix = 'APP\\plugins\\generic\\dossierManager\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 3) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
