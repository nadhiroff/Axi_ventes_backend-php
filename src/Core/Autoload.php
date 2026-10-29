<?php
declare(strict_types=1);

namespace Axi\Core;

/** Chargement automatique : Axi\Core\Router => src/Core/Router.php */
final class Autoload
{
    public static function register(string $srcDir): void
    {
        spl_autoload_register(static function (string $class) use ($srcDir): void {
            $prefix = 'Axi\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = $srcDir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
