<?php

namespace Amp\Http\Server\Session;

use Amp\Coroutine;
use Amp\Promise;
use Amp\Redis\SubscribeClient;
use Amp\Redis\Subscription;
use function Amp\call;

class RedisPublishSubscriber
{
    /** @var SubscribeClient */
    private $client;

    /** @var string */
    private $keyPrefix;

    /** @var callable[] */
    private $updateHandlers = [];

    /** @var callable|null */
    private $errorHandler;

    /** @var Subscription[] */
    private $subscriptions = [];

    /** @var \Throwable|null */
    private $error;

    public function __construct(SubscribeClient $client, string $keyPrefix = 'sess:')
    {
        $this->client = $client;
        $this->keyPrefix = $keyPrefix;
    }

    public function processEvents(): Promise
    {
        return call(function () {
            try {
                yield new Coroutine($this->handleUpdateEvents());

                if ($this->error) {
                    throw $this->error;
                }
            } finally {
                foreach ($this->subscriptions as $subscription) {
                    $subscription->cancel();
                }

                $this->subscriptions = [];
            }
        });
    }

    public function addUpdateHandler(callable $callback)
    {
        $this->updateHandlers[] = $callback;
    }

    public function setErrorHandler(callable $callback)
    {
        $this->errorHandler = $callback;
    }

    /** @throws */
    private function handleUpdateEvents(): \Generator
    {
        /** @var Subscription $subscription */
        $subscription = yield $this->client->subscribe($this->keyPrefix . 'save');
        $this->subscriptions[] = $subscription;

        while (yield $subscription->advance()) {
            $update = $subscription->getCurrent();
            list($id, $data) = \explode(' ', $update, 2);

            foreach ($this->updateHandlers as $handler) {
                call($handler, $id, \unserialize($data, ['allowed_classes' => true]))->onResolve(function ($error) {
                    if ($error) {
                        $this->triggerErrorHandlers($error);
                    }
                });
            }
        }
    }

    private function triggerErrorHandlers(\Throwable $e)
    {
        call($this->errorHandler, $e)->onResolve(function ($error) {
            if ($error) {
                $this->error = $error;
                foreach ($this->subscriptions as $subscription) {
                    $subscription->cancel();
                }

                $this->subscriptions = [];
            }
        });
    }
}
