<?php

declare(strict_types = 1);

namespace SA\MessengerBridge;

use Closure;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\Command\DebugCommand;
use Symfony\Component\Messenger\Command\FailedMessagesRemoveCommand;
use Symfony\Component\Messenger\Command\FailedMessagesRetryCommand;
use Symfony\Component\Messenger\Command\FailedMessagesShowCommand;
use Symfony\Component\Messenger\Command\SetupTransportsCommand;
use Symfony\Component\Messenger\Command\StopWorkersCommand;
use Symfony\Component\Messenger\EventListener\DispatchPcntlSignalListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnSigtermSignalListener;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Handler\MessageSubscriberInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\AddBusNameStampMiddleware;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\FailedMessageProcessingMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\RejectRedeliveredMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\AmqpExt\AmqpTransportFactory;
use Symfony\Component\Messenger\Transport\InMemoryTransportFactory;
use Symfony\Component\Messenger\Transport\RedisExt\RedisTransportFactory;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Sync\SyncTransportFactory;
use Symfony\Component\Messenger\Transport\TransportFactory;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;

/**
 * Class Provider
 * @package SA\MessengerBridge
 */
class Provider extends ServiceProvider
{
    /**
     * @var array
     */
    private $transports = [];

    /**
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../../config/messenger.php' => config_path('messenger.php')]);

        $this->registerCommands();
    }

    /**
     * @throws ReflectionException
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/messenger.php', 'messenger');

        /** @var Config $config */
        $config = $this->app->get('config');

        $configMessenger = $config->get('messenger');

        $this->app->singleton(AmqpTransportFactory::class);
        $this->app->singleton(RedisTransportFactory::class);
        $this->app->singleton(SyncTransportFactory::class);
        $this->app->singleton(InMemoryTransportFactory::class);
        $this->app->tag([
            AmqpTransportFactory::class,
            RedisTransportFactory::class,
            SyncTransportFactory::class,
            InMemoryTransportFactory::class,
        ], ['messenger.transport_factory']);

        $this->app->singleton(TransportFactoryInterface::class, function(Container $container) {
            return new TransportFactory($container->tagged('messenger.transport_factory'));
        });

        $this->app->singleton('messenger.transport.native_php_serializer', PhpSerializer::class);
        $this->app->alias($config['serializer']['default_serializer'] ?? 'messenger.transport.native_php_serializer', 'messenger.default_serializer');

        if (!$this->app->bound(EventDispatcherInterface::class)) {
            $this->app->singleton(EventDispatcherInterface::class, EventDispatcher::class);
        }

        $this->app->resolving(EventDispatcherInterface::class, function (EventDispatcherInterface $eventDispatcher, Container $container) {
            $subscribers = array_filter([
                DispatchPcntlSignalListener::class,
                SendFailedMessageForRetryListener::class,
                ($configMessenger['failure_transport'] ?? null)
                    ? SendFailedMessageToFailureTransportListener::class
                    : null,
                StopWorkerOnRestartSignalListener::class,
                StopWorkerOnSigtermSignalListener::class,
            ]);

            foreach ($subscribers as $subscriberClass) {
                $eventDispatcher->addSubscriber($container->get($subscriberClass));
            }
        });

        if (!$this->app->bound('cache.psr6')) {
            $this->app->singleton('cache.psr6', function (Container $container) {
                return new Psr16Adapter($container->get('cache.store'));
            });
        }
        $this->app->alias('cache.psr6', CacheItemPoolInterface::class);

        $this->app->singleton('messenger.routable_message_bus', function(Container $container) {
            return new RoutableMessageBus(
                $container,
                $container->get('messenger.default_bus')
            );
        });

        $defaultMiddleware = [
            'before' => [
                ['id' => 'add_bus_name_stamp_middleware', 'class' => AddBusNameStampMiddleware::class],
                ['id' => 'reject_redelivered_message_middleware', 'class' => RejectRedeliveredMessageMiddleware::class],
                ['id' => 'dispatch_after_current_bus', 'class' => DispatchAfterCurrentBusMiddleware::class],
                ['id' => 'failed_message_processing_middleware', 'class' => FailedMessageProcessingMiddleware::class],
            ],
            'after' => [
                ['id' => 'send_message', 'class' => SendMessageMiddleware::class],
                ['id' => 'handle_message', 'class' => HandleMessageMiddleware::class],
            ],
        ];

        if (null === $configMessenger['default_bus'] && 1 === count($configMessenger['buses'])) {
            $configMessenger['default_bus'] = key($configMessenger['buses']);
        }

        $middleware = [];
        $busIds = [];
        foreach ($configMessenger['buses'] as $busId => $bus) {
            $middlewareBus = $bus['middleware'];

            if ($bus['default_middleware']) {
                if ('allow_no_handlers' === $bus['default_middleware']) {
                    $defaultMiddleware['after'][1]['arguments'] = ['allowNoHandlers' => true];
                } else {
                    unset($defaultMiddleware['after'][1]['arguments']);
                }

                // argument to AddBusNameStampMiddleware::class
                $defaultMiddleware['before'][0]['arguments'] = ['busName' => $busId];

                $middlewareBus = array_merge($defaultMiddleware['before'], $middlewareBus, $defaultMiddleware['after']);
            }

            $middleware[$busId] = $middlewareBus;

            if ($busId === $configMessenger['default_bus']) {
                $this->app->alias($busId, 'messenger.default_bus');
                $this->app->alias($busId, MessageBusInterface::class);
            }

            $busIds[] = $busId;
        }

        $busMiddlewareIds = [];

        foreach ($middleware as $busId => $middlewareBus) {
            $middlewareIds = [];
            foreach ($middlewareBus as $middlewareItem) {
                $messengerMiddlewareId = $middlewareItem['id'];
                $busMiddlewareIds[$busMiddlewareId = $busId . '.middleware.' . $messengerMiddlewareId] = $middlewareItem;
                $middlewareIds[] = $busMiddlewareId;
                if ($messengerMiddlewareId !== 'handle_message' && !$this->app->bound($messengerMiddlewareId)) {
                    $this->app->singleton($busMiddlewareId, function (Container $container) use ($middlewareItem) {
                        return $container->make($middlewareItem['class'], $middlewareItem['arguments'] ?? []);
                    });
                }
            }
            $this->app->singleton($busId, function (Container $container) use ($middlewareIds) {
                $middlewareHandlers = [];
                foreach ($middlewareIds as $middlewareId) {
                    $middlewareHandlers[] = $container->get($middlewareId);
                }
                return new MessageBus($middlewareHandlers);
            });
        }

        $this->registerTransports($configMessenger['transports'] ?? [], $configMessenger['routing'] ?? []);

        $this->registerFailureTransport($configMessenger['failure_transport'] ?? null);

        $this->registerHandlers($busIds, $busMiddlewareIds);

        $this->registerReceivers();
    }

    /**
     * @param array $transports
     * @param array $routing
     */
    private function registerTransports(array $transports, array $routing): void
    {
        $retryStrategyLocator = new \Illuminate\Container\Container();
        foreach ($transports as $name => $transport) {
            $serializerId = $transport['serializer'] ?? 'messenger.default_serializer';

            $this->app->singleton($transportId = 'messenger.transport.' . $name, function(Container $container) use ($transport, $name, $serializerId) {
                $transportFactory = $container->get(TransportFactoryInterface::class);

                return $transportFactory->createTransport(
                    $transport['dsn'],
                    $transport['options'] + ['transport_name' => $name],
                    $container->get($serializerId)
                );
            });
            $this->app->alias($transportId, $name);

            $this->transports[$name] = $transportId;

            if (null === $transport['retry_strategy']['service']) {
                $retryServiceId = sprintf('messenger.retry.multiplier_retry_strategy.%s', $name);
                $retryStrategyLocator->singleton($retryServiceId, function() use ($transport) {
                    return new MultiplierRetryStrategy(
                        $transport['retry_strategy']['max_retries'],
                        $transport['retry_strategy']['delay'],
                        $transport['retry_strategy']['multiplier'],
                        $transport['retry_strategy']['max_delay']
                    );
                });
                $retryStrategyLocator->alias($retryServiceId, $name);
            } else {
                $retryStrategyLocator->alias($transport['retry_strategy']['service'], $name);
            }
        }
        $this->app->tag($this->transports, ['messenger.receiver']);

        $messageToSendersMapping = [];
        foreach ($routing as $message => $messageConfiguration) {
            if ('*' !== $message && !class_exists($message) && !interface_exists($message, false)) {
                throw new LogicException(sprintf('Invalid Messenger routing configuration: class or interface "%s" not found.', $message));
            }

            foreach ($messageConfiguration['senders'] as $sender) {
                if (!$this->app->has($sender)) {
                    throw new LogicException(sprintf('Invalid Messenger routing configuration: the "%s" class is being routed to a sender called "%s". This is not a valid transport or service id.', $message, $sender));
                }
            }

            $messageToSendersMapping[$message] = $messageConfiguration['senders'];
        }

        $this->app->singleton(SendersLocatorInterface::class, function(Container $container) use ($messageToSendersMapping) {
            return new SendersLocator($messageToSendersMapping, $container);
        });
        $this->app->alias(SendersLocatorInterface::class, 'messenger.senders_locator');

        $this->app->singleton('messenger.retry.send_failed_message_for_retry_listener', function(Container $appContainer) use($retryStrategyLocator) {
            return new SendFailedMessageForRetryListener(
                $appContainer,
                $retryStrategyLocator,
                $appContainer->get(LoggerInterface::class)
            );
        });
        $this->app->alias('messenger.retry.send_failed_message_for_retry_listener', SendFailedMessageForRetryListener::class);
    }

    /**
     * @param $failureTransport
     */
    private function registerFailureTransport($failureTransport): void
    {
        if ($failureTransport) {
            if (!$this->app->has($failureTransport)) {
                throw new LogicException(sprintf('Invalid Messenger configuration: the failure transport "%s" is not a valid transport or service id.', $failureTransport));
            }

            $this->app->singleton(SendFailedMessageToFailureTransportListener::class, function (Container $container) use ($failureTransport) {
                return new SendFailedMessageToFailureTransportListener($container->get($failureTransport), $container->get(LoggerInterface::class));
            });
            $this->app->alias(SendFailedMessageToFailureTransportListener::class, 'messenger.failure.send_failed_message_to_failure_transport_listener');

            $failedCommandIds = [
                'console.command.messenger_failed_messages_retry' => FailedMessagesRetryCommand::class,
                'console.command.messenger_failed_messages_show' => FailedMessagesShowCommand::class,
                'console.command.messenger_failed_messages_remove' => FailedMessagesRemoveCommand::class,
            ];

            foreach ($failedCommandIds as $failedCommandId => $failedCommandClass) {
                $this->app->singleton($failedCommandClass, function(Container $container) use ($failedCommandClass, $failureTransport) {
                    $arguments = [
                        $failureTransport, //receiverName
                        $container->get(current($this->transports)), //receiver
                        $container->get('messenger.routable_message_bus'), //messageBus
                        $container->get(EventDispatcherInterface::class), //eventDispatcher
                        $container->get(LoggerInterface::class), //logger
                    ];

                    return new $failedCommandClass(...$arguments);
                });
                $this->app->alias($failedCommandClass, $failedCommandId);
            }
        }
    }

    /**
     * @param array $busIds
     * @param array $busMiddlewareIds
     *
     * @throws ReflectionException
     */
    private function registerHandlers(array $busIds, array $busMiddlewareIds): void
    {
        $callableHandler = [];
        $handlersByBusAndMessage = [];
        $handlerToOriginalServiceIdMapping = [];

        foreach ($this->app->tagged('messenger.message_handler') as $handler) {
            $handlerReflection = new ReflectionClass($handler);
            $handles = $this->guessHandledClasses($handlerReflection);

            foreach ($handles as $message => $options) {
                $buses = $busIds;

                if (is_int($message)) {
                    if (is_string($options)) {
                        $message = $options;
                        $options = [];
                    } else {
                        throw new RuntimeException(sprintf('The handler configuration needs to return an array of messages or an associated array of message and configuration. Found value of type "%s" at position "%d" for service "%s".', gettype($options), $message, $handlerReflection->getName()));
                    }
                }

                if (is_string($options)) {
                    $options = ['method' => $options];
                }

                $priority = $tag['priority'] ?? $options['priority'] ?? 0;
                $method = $options['method'] ?? '__invoke';

                if (isset($options['bus'])) {
                    if (!in_array($options['bus'], $busIds)) {
                        $messageLocation = isset($tag['handles']) ? 'declared in your tag attribute "handles"' : ($handlerReflection->implementsInterface(MessageSubscriberInterface::class) ? sprintf('returned by method "%s::getHandledMessages()"', $handlerReflection->getName()) : sprintf('used as argument type in method "%s::%s()"', $handlerReflection->getName(), $method));

                        throw new RuntimeException(sprintf('Invalid configuration %s for message "%s": bus "%s" does not exist.', $messageLocation, $message, $options['bus']));
                    }

                    $buses = [$options['bus']];
                }

                if ('*' !== $message && !class_exists($message) && !interface_exists($message, false)) {
                    $messageLocation = $handlerReflection->implementsInterface(MessageSubscriberInterface::class) ? sprintf('returned by method "%s::getHandledMessages()"', $handlerReflection->getName()) : sprintf('used as argument type in method "%s::%s()"', $handlerReflection->getName(), $method);

                    throw new RuntimeException(sprintf('Invalid handler service "%s": class or interface "%s" %s not found.', $handlerReflection->getName(), $message, $messageLocation));
                }

                if (!$handlerReflection->hasMethod($method)) {
                    throw new RuntimeException(sprintf('Invalid handler service "%s": method "%s::%s()" does not exist.', $handlerReflection->getName(), $handlerReflection->getName(), $method));
                }

                if ('__invoke' !== $method) {
                    $callableHandler[$definitionId = '.messenger.method_on_object_wrapper.' . self::hash($message . ':' . $priority . ':' . $handlerReflection->getName() . ':' . $method)] = Closure::fromCallable([$handlerReflection->getName(), $method]);
                } else {
                    $definitionId = $handlerReflection->getName();
                }

                $handlerToOriginalServiceIdMapping[$definitionId] = $handlerReflection->getName();

                foreach ($buses as $handlerBus) {
                    $handlersByBusAndMessage[$handlerBus][$message][$priority][] = [$definitionId, $options];
                }
            }
        }

        foreach ($handlersByBusAndMessage as $bus => $handlersByMessage) {
            foreach ($handlersByMessage as $message => $handlersByPriority) {
                krsort($handlersByPriority);
                $handlersByBusAndMessage[$bus][$message] = array_merge(...$handlersByPriority);
            }
        }

        $handlersLocatorMappingByBus = [];
        foreach ($handlersByBusAndMessage as $bus => $handlersByMessage) {
            foreach ($handlersByMessage as $message => $handlers) {
                $handlerDescriptors = [];
                foreach ($handlers as $handler) {
                    $definitionId = '.messenger.handler_descriptor.' . self::hash($bus . ':' . $message . ':' . $handler[0]);

                    $this->app->singleton($definitionId, function(Container $container) use ($handler, $callableHandler) {
                        return new HandlerDescriptor($callableHandler[$handler[0]] ?? $container->get($handler[0]), $handler[1]);
                    });

                    $handlerDescriptors[] = $definitionId;
                }

                $handlersLocatorMappingByBus[$bus][$message] = $handlerDescriptors;
            }
        }

        foreach ($busIds as $bus) {
            $this->app->singleton($locatorId = $bus . '.messenger.handlers_locator', function() use ($handlersLocatorMappingByBus, $bus) {
                $messages = $handlersLocatorMappingByBus[$bus] ?? [];
                $handlers = [];
                foreach ($messages as $message => $handlerIds) {
                    foreach ($handlerIds as $handlerId) {
                        $handlers[] = $this->app->get($handlerId);
                    }
                    $handlersLocatorMappingByBus[$bus][$message] = $handlers;
                }
                return new HandlersLocator($handlersLocatorMappingByBus[$bus] ?? []);
            });

            if (array_key_exists($handleMessageId = $bus.'.middleware.handle_message', $busMiddlewareIds)) {
                $this->app->singleton($handleMessageId, function (Container $container) use ($busMiddlewareIds, $handleMessageId, $locatorId) {
                    return $container->make(
                        $busMiddlewareIds[$handleMessageId]['class'],
                        array_merge($busMiddlewareIds[$handleMessageId]['arguments'] ?? [], ['handlersLocator' => $container->get($locatorId)])
                    );
                });
            }
        }

        $debugCommandMapping = $handlersByBusAndMessage;
        foreach ($busIds as $bus) {
            if (!isset($debugCommandMapping[$bus])) {
                $debugCommandMapping[$bus] = [];
            }

            foreach ($debugCommandMapping[$bus] as $message => $handlers) {
                foreach ($handlers as $key => $handler) {
                    $debugCommandMapping[$bus][$message][$key][0] = $handlerToOriginalServiceIdMapping[$handler[0]];
                }
            }
        }

        $this->app->singleton(DebugCommand::class, function() use ($debugCommandMapping) {
            return new DebugCommand($debugCommandMapping);
        });
    }

    private function registerReceivers(): void
    {
        $this->app->singleton('messenger.routable_message_bus', function(Container $container) {
            return new RoutableMessageBus($container);
        });
        $this->app->alias('messenger.routable_message_bus', RoutableMessageBus::class);

        $this->app->singleton(ConsumeMessagesCommand::class, function(Container $container) {
            return new ConsumeMessagesCommand(
                $container->get('messenger.routable_message_bus'),
                $container,
                $container->get(EventDispatcherInterface::class),
                $container->get(LoggerInterface::class),
                array_keys($this->transports)
            );
        });
        $this->app->alias(ConsumeMessagesCommand::class, 'console.command.messenger_consume_messages');

        $this->app->singleton(StopWorkersCommand::class, function(Container $container) {
            return new StopWorkersCommand(
                $container->get('cache.psr6')
            );
        });
        $this->app->alias(ConsumeMessagesCommand::class, 'console.command.messenger_consume_messages');

        $this->app->singleton(SetupTransportsCommand::class, function(Container $container) {
            return new SetupTransportsCommand(
                $container,
                array_keys($this->transports)
            );
        });
        $this->app->alias(SetupTransportsCommand::class, 'console.command.messenger_consume_messages');
    }

    private function registerCommands()
    {
        $this->commands([
            ConsumeMessagesCommand::class,
            StopWorkersCommand::class,
            SetupTransportsCommand::class,
            DebugCommand::class,
        ]);

        /** @var Config $config */
        $config = $this->app->get('config');

        $configMessenger = $config->get('messenger');
        if ($configMessenger['failure_transport'] ?? null) {
            $this->commands([
                FailedMessagesRemoveCommand::class,
                FailedMessagesShowCommand::class,
                FailedMessagesRetryCommand::class,
            ]);
        }
    }

    /**
     * @param ReflectionClass $handlerClass
     *
     * @return iterable
     *
     * @throws RuntimeException
     */
    private function guessHandledClasses(ReflectionClass $handlerClass): iterable
    {
        if ($handlerClass->implementsInterface(MessageSubscriberInterface::class)) {
            return $handlerClass->getName()::getHandledMessages();
        }

        try {
            $method = $handlerClass->getMethod('__invoke');
        } catch (ReflectionException $e) {
            throw new RuntimeException(sprintf('Invalid handler service: class "%s" must have an "__invoke()" method.', $handlerClass->getName()));
        }

        if (0 === $method->getNumberOfRequiredParameters()) {
            throw new RuntimeException(sprintf('Invalid handler service: method "%s::__invoke()" requires at least one argument, first one being the message it handles.', $handlerClass->getName()));
        }

        $parameters = $method->getParameters();
        if (!$type = $parameters[0]->getType()) {
            throw new RuntimeException(sprintf('Invalid handler service: argument "$%s" of method "%s::__invoke()" must have a type-hint corresponding to the message class it handles.', $parameters[0]->getName(), $handlerClass->getName()));
        }

        if ($type->isBuiltin()) {
            throw new RuntimeException(sprintf('Invalid handler service: type-hint of argument "$%s" in method "%s::__invoke()" must be a class , "%s" given.', $parameters[0]->getName(), $handlerClass->getName(), $type instanceof ReflectionNamedType ? $type->getName() : (string) $type));
        }

        return [$parameters[0]->getType()->getName()];
    }

    /**
     * @param $value
     *
     * @return string|string[]
     */
    private static function hash($value)
    {
        $hash = substr(base64_encode(hash('sha256', serialize($value), true)), 0, 7);

        return str_replace(['/', '+'], ['.', '_'], $hash);
    }
}
