<?php

declare(strict_types=1);

namespace App\Tests\Support;

use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Runner\ApplicationRunner;

use function microtime;

final class FunctionalApplicationRunner extends ApplicationRunner
{
    public function run(): void
    {
        throw new LogicException('Use runAndGetResponse() in functional tests.');
    }

    public function runAndGetResponse(ServerRequestInterface $request): ResponseInterface
    {
        $this->runBootstrap();
        $this->checkEvents();

        /** @var Application $application */
        $application = $this->getContainer()->get(Application::class);

        $request = $request->withAttribute('applicationStartTime', microtime(true));

        $application->start();
        try {
            return $response = $application->handle($request);
        } finally {
            $application->afterEmit($response ?? null);
            $application->shutdown();
        }
    }
}
