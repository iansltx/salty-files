<?php

use App\AuthMiddleware;
use App\AuthRepository;
use App\FileRepository;
use App\UserRepository;
use Aura\Sql\ExtendedPdo;
use Aura\Sql\ExtendedPdoInterface;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use ParagonIE\Halite\Symmetric\EncryptionKey;
use ParagonIE\HiddenString\HiddenString;
use ParagonIE\Paseto\Keys\Version2\SymmetricKey;
use Pimple\Container;

return function (Container $container, array $env) {
    $key = base64_decode($env['APP_KEY']);

    $container['db'] = function () use ($env): ExtendedPdoInterface {
        return new ExtendedPdo('mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'], $env['DB_USER'],
            $env['DB_PASSWORD']);
    };
    $container['fs'] = fn ($c) => new Filesystem(new Local(__DIR__ . '/../files'));

    $container['userRepo'] = function ($c) use ($key) {
        return new UserRepository($c['db'], new EncryptionKey(new HiddenString($key)));
    };
    $container['authRepo'] = function ($c) use ($key, $env) {
        return new AuthRepository(
            $c['db'],
            new EncryptionKey(new HiddenString($key)),
            new SymmetricKey($key),
            $env['ISSUER']
        );
    };
    $container['fileRepo'] = function ($c) {
        return new FileRepository($c['db'], $c['fs']);
    };

    $container['view'] = fn () => new App\View(__DIR__ . '/../templates');
    $container['authMiddleware'] = fn ($c) => new AuthMiddleware($c['authRepo']);
};
