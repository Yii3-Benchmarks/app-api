<?php

declare(strict_types=1);

use App\Environment;
use Psr\Log\LogLevel;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Renderer\JsonRenderer;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;
use Yiisoft\Yii\Runner\RoadRunner\RoadRunnerHttpApplicationRunner;

$root = __DIR__;

ini_set('display_errors', 'stderr');

require_once $root . '/src/bootstrap.php';

(new RoadRunnerHttpApplicationRunner(
    rootPath: $root,
    debug: Environment::appDebug(),
    checkEvents: Environment::appDebug(),
    environment: Environment::appEnv(),
))
    ->withTemporaryErrorHandler(
        new ErrorHandler(
            new Logger(
                [
                    (new StreamTarget())->setLevels([
                        LogLevel::EMERGENCY,
                        LogLevel::ERROR,
                        LogLevel::WARNING,
                    ]),
                ],
            ),
            new JsonRenderer(),
        ),
    )
    ->run();
