<?php

namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Http\Response;
use Slim\Psr7\Factory\StreamFactory;

class ErrorMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\InvalidArgumentException $e) {
            return (new Response(new \Slim\Psr7\Response($e->getCode() ?: 400), new StreamFactory()))
                ->withJson(['message' => $e->getMessage()]);
        }
    }
}
