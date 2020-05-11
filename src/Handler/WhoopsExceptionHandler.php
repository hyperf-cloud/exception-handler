<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\ExceptionHandler\Handler;

use Hyperf\Contract\SessionInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Utils\Context;
use Hyperf\Utils\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Whoops\Handler\JsonResponseHandler;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Handler\XmlResponseHandler;
use Whoops\Run;

class WhoopsExceptionHandler extends ExceptionHandler
{
    protected static $preference = [
        'text/html' => PrettyPageHandler::class,
        'application/json' => JsonResponseHandler::class,
        'application/xml' => XmlResponseHandler::class,
    ];

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $whoops = new Run();
        [$handler, $contentType] = $this->negotiateHandler();

        // CLI mode restriction hack
        if (method_exists($handler, 'handleUnconditionally')) {
            $handler->handleUnconditionally(true);
        }

        $whoops->pushHandler($handler);
        $whoops->allowQuit(false);
        ob_start();
        $whoops->{Run::EXCEPTION_HANDLER}($throwable);
        $content = ob_get_clean();
        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', $contentType)
            ->withBody(new SwooleStream($content));
    }

    public function isValid(Throwable $throwable): bool
    {
        return env('APP_ENV') !== 'prod' && class_exists(Run::class);
    }

    private function negotiateHandler()
    {
        /** @var ServerRequestInterface $request */
        $request = Context::get(ServerRequestInterface::class);
        $accepts = $request->getHeaderLine('accept');
        foreach (self::$preference as $contentType => $handler) {
            if (Str::contains($accepts, $contentType)) {
                return [$this->setupHandler(new $handler()),  $contentType];
            }
        }
        return [new PlainTextHandler(),  'text/plain'];
    }

    private function setupHandler($handler)
    {
        if ($handler instanceof PrettyPageHandler) {
            $handler->handleUnconditionally(true);

            if (defined('BASE_PATH')) {
                $handler->setApplicationRootPath(BASE_PATH);
            }

            $request = Context::get(ServerRequestInterface::class);
            if ($request) {
                $handler->addDataTableCallback('PSR7 Query', [$request, 'getQueryParams']);
                $handler->addDataTableCallback('PSR7 Post', [$request, 'getParsedBody']);
                $handler->addDataTableCallback('PSR7 Server', [$request, 'getServerParams']);
                $handler->addDataTableCallback('PSR7 Cookie', [$request, 'getCookieParams']);
                $handler->addDataTableCallback('PSR7 File', [$request, 'getUploadedFiles']);
                $handler->addDataTableCallback('PSR7 Attribute', [$request, 'getAttributes']);
            }

            $session = Context::get(SessionInterface::class);
            if ($session) {
                $handler->addDataTableCallback('Hyperf Session', [$session, 'all']);
            }
        }

        return $handler;
    }
}
