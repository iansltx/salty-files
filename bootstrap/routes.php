<?php

use App\AuthMiddleware;
use Slim\App;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Slim\Interfaces\RouteCollectorProxyInterface;

return function(App $app) {
    // SPA-ish thing
    $app->get('/', function (Request $request, Response $response) {
        return $this->get('view')->render($response, 'home');
    });

    // LOGIN

    $app->post('/sessions', function (Request $request, Response $response) {
        return $response->withJson($this->get('authRepo')->login(
            $request->getParsedBodyParam('username'),
            $request->getParsedBodyParam('password')
        ));
    });

    // USER MANAGEMENT

    $app->post('/users', function (Request $request, Response $response) {
        $this->get('userRepo')->create(
            $request->getParsedBodyParam('username'),
            $request->getParsedBodyParam('password'),
            $request->getParsedBodyParam('password_confirm')
        );

        return $response->withJson($this->get('authRepo')->login(
            $request->getParsedBodyParam('username'),
            $request->getParsedBodyParam('password')
        ));
    });

    $app->post('/password_resets', function (Request $request, Response $response) {
        $response->withJson($this->get('userRepo')->resetPassword(
            $request->getParsedBodyParam('username'),
            $request->getParsedBodyParam('key'),
            $request->getParsedBodyParam('password'),
            $request->getParsedBodyParam('password_confirm')
        ));

        return $response->withJson(['message' => 'Password reset successfully']);
    });

    $app->get('/me/key', function (Request $request, Response $response) {
        return $response->withJson([
            'key' => base64_encode(AuthMiddleware::getUser($request)->getKey()->getRawKeyMaterial())
        ]);
    })->add('authMiddleware');

    $app->post('/me/password_changes', function (Request $request, Response $response) {
        $response->withJson($this->get('userRepo')->updatePassword(
            AuthMiddleware::getUser($request),
            $request->getParsedBodyParam('current_password'),
            $request->getParsedBodyParam('new_password'),
            $request->getParsedBodyParam('new_password_confirm')
        ));

        return $response->withJson(['message' => 'Password changed successfully']);
    })->add('authMiddleware');

    // FILE MANAGEMENT

    $app->group('/files', function (RouteCollectorProxyInterface $group) {
        $group->get('', function (Request $request, Response $response) {
            return $response->withJson($this->get('fileRepo')->forUser(AuthMiddleware::getUser($request)));
        });

        $group->get('/{id}', function (Request $request, Response $response, array $params) {
            return $this->get('fileRepo')->retrieve($params['id'], AuthMiddleware::getUser($request), $response);
        });

        $group->delete('/{id}', function (Request $request, Response $response, array $params) {
            $this->get('fileRepo')->delete($params['id'], AuthMiddleware::getUser($request));

            return $response->withStatus(205);
        });

        $group->post('/{id}/share/{username}', function (Request $request, Response $response, array $params) {
            $this->get('fileRepo')->share($params['id'], $params['username'], AuthMiddleware::getUser($request));

            return $response->withStatus(205);
        });

        $group->delete('/{id}/share/{username}', function (Request $request, Response $response, array $params) {
            $this->get('fileRepo')->unshare($params['id'], $params['username'], AuthMiddleware::getUser($request));

            return $response->withStatus(205);
        });

        $group->post('', function (Request $request, Response $response) {
            $files = $request->getUploadedFiles();
            if (!$files) {
                throw new \InvalidArgumentException('No file was uploaded');
            }

            return $response
                ->withJson($this->get('fileRepo')->upload($files['file'], AuthMiddleware::getUser($request)))
                ->withStatus(201);
        });
    })->add('authMiddleware');
};
