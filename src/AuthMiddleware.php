<?php

namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthMiddleware implements MiddlewareInterface
{
    // assumes this middleware's auth has already been applied
    public static function getUser(ServerRequestInterface $request): User
    {
        return $request->getAttribute('user');
    }

    protected AuthRepository $authRepo;

    public function __construct(AuthRepository $authRepo)
    {
        $this->authRepo = $authRepo;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strpos($authHeader = $request->getHeaderLine('Authorization'), 'Bearer ') !== 0) {
            throw new \InvalidArgumentException('Missing auth header', 401);
        }

        return $handler->handle(
            $request->withAttribute('user', $this->authRepo->validateSession(substr($authHeader, 7)))
        );
    }
}
