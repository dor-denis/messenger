<?php

namespace Symfony\Component\Messenger;

use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Transport\Receiver\BlockingReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Exception\RuntimeException;

class BlockingWorker extends Worker
{
    public function run(array $options = []): void
    {
        $this->dispatchEvent(new WorkerStartedEvent($this));

        $options    = array_merge([
                                      'sleep' => 1000000,
                                  ], $options);
        $queueNames = $options['queues'] ?? false;

        if ($queueNames) {
            // if queue names are specified, all receivers must implement the QueueReceiverInterface
            foreach ($this->receivers as $transportName => $receiver) {
                if (!$receiver instanceof BlockingReceiverInterface) {
                    throw new RuntimeException(sprintf('Receiver for "%s" does not implement "%s".', $transportName, BlockingReceiverInterface::class));
                }
            }
        }

        $self = $this;
        while (false === $this->shouldStop) {
            $envelopeHandled = false;
            foreach ($this->receivers as $transportName => $receiver) {
                /** @var BlockingReceiverInterface $receiver */
                $receiver->pull(function (Envelope $envelope) use ($receiver, $transportName, $self, &$envelopeHandled) {
                    $envelopeHandled = true;

                    $this->handleMessage($envelope, $receiver, $transportName);
                    $this->dispatchEvent(new WorkerRunningEvent($self, false));

                    if ($this->shouldStop) {
                        return false;
                    }

                    return true;
                });

                // after handling a single receiver, quit and start the loop again
                // this should prevent multiple lower priority receivers from
                // blocking too long before the higher priority are checked
                if ($envelopeHandled) {
                    break;
                }
            }

            if (false === $envelopeHandled) {
                $this->dispatchEvent(new WorkerRunningEvent($this, true));

                usleep($options['sleep']);
            }
        }

        $this->dispatchEvent(new WorkerStoppedEvent($this));
    }
}