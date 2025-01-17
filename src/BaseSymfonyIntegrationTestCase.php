<?php

declare(strict_types=1);

namespace Mekras\Symfony\BundleTesting;

use Mekras\Symfony\BundleTesting\CompilerPass\CallbackPass;
use Mekras\Symfony\BundleTesting\DependencyInjection\ContainerExpectations;
use Mekras\Symfony\BundleTesting\DependencyInjection\TestContainerBuilder;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

/**
 * Основной класс для тестов интеграции с Symfony
 *
 * @since 0.1
 */
abstract class BaseSymfonyIntegrationTestCase extends TestCase
{
    /**
     * Проверяемые контейнеры
     *
     * @var TestContainerBuilder[]
     */
    private array $containers = [];

    /**
     * Поставщик возможных форматов файлов конфигурации
     *
     * @return array<string, array<string>>
     *
     * @since 0.1
     */
    public static function configTypeProvider(): array
    {
        return [
            'PHP' => ['php'],
            'XML' => ['xml'],
            'YAML' => ['yml'],
        ];
    }

    /**
     * Добавляет службы в локатор, чтобы их можно было извлечь после компиляции контейнера
     *
     * Извлечь службу после компиляции можно методом {@see getFromLocator()}.
     *
     * @param array<string>    $serviceIds Добавляемые идентификаторы.
     * @param ContainerBuilder $container  Конструктор контейнера.
     *
     * @throws \Throwable
     *
     * @deprecated 0.3.0 Используйте {@see TestContainerBuilder::makeLocatable()}.
     * @since      0.1
     */
    protected function addToLocator(array $serviceIds, ContainerBuilder $container): void
    {
        \trigger_error('Использование устаревшего метода ' . __METHOD__, \E_USER_DEPRECATED);

        if ($container instanceof TestContainerBuilder) {
            $container->makeLocatable(...$serviceIds);
        } else {
            $references = array_map(
                static fn(string $serviceId): Reference => new Reference($serviceId),
                $serviceIds
            );
            $container->setDefinition(
                'test_locator',
                (new Definition(ServiceLocator::class))
                    ->setPublic(true)
                    ->addArgument($references)
                    ->addTag('container.service_locator')
            );
        }
    }

    /**
     * Создаёт контейнер зависимостей
     *
     * ВНИМАНИЕ! Перед использованием контейнер надо скомпилировать вызвав метод compile().
     *
     * @param array<string> $public Зависимости, которые надо сделать публичными.
     *
     * @throws \Throwable
     *
     * @since 0.3.0 Тип возвращаемого значения изменён с ContainerBuilder на TestContainerBuilder.
     * @since 0.3.0 Аргумент $public объявлен устаревшим.
     * @since 0.1
     */
    protected function createContainer(array $public = []): TestContainerBuilder
    {
        if ($public !== []) {
            \trigger_error(
                'Использование аргумента $public является устаревшим. Используйте TestContainerBuilder::makePublic().',
                \E_USER_DEPRECATED
            );
        }
        $container = new TestContainerBuilder();

        $container->setParameter('kernel.build_dir', $this->getTempDir());
        $container->setParameter('kernel.bundles_metadata', []);
        $container->setParameter('kernel.cache_dir', $this->getTempDir());
        $container->setParameter('kernel.charset', 'UTF-8');
        $container->setParameter('kernel.container_class', 'TestContainer');
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.environment', 'dev');
        $container->setParameter('kernel.logs_dir', $this->getTempDir());
        $container->setParameter('kernel.project_dir', $this->getTempDir());
        $container->setParameter('kernel.root_dir', $this->getTempDir());
        $container->setParameter('kernel.runtime_environment', 'dev');
        $container->setParameter('kernel.runtime_mode.web', true);
        $container->setParameter('kernel.secret', 'test');

        foreach ($this->getRequiredBundles() as $className) {
            $container->registerBundle($className);
        }

        $container->registerBundle($this->getBundleClass());

        $container->addCompilerPass(
            new CallbackPass(
                function (ContainerBuilder $container) use ($public): void {
                    \reset($public);
                    while ($id = \current($public)) {
                        if ($container->hasDefinition($id)) {
                            $definition = $container->getDefinition($id);
                            $definition->setPublic(true);
                        } elseif ($container->hasAlias($id)) {
                            $alias = $container->getAlias($id);
                            $alias->setPublic(true);
                            $public[] = (string) $alias;
                        } else {
                            $this->fail(sprintf('В контейнере нет объявления «%s».', $id));
                        }
                        \next($public);
                    }
                }
            )
        );

        return $container;
    }

    /**
     * Загружает контейнер зависимостей из файла
     *
     * Файлы конфигурации контейнеров можно размещать в любой папке (путь к ней надо передавать в
     * аргументе $configDir). Внутри неё для каждого варианта конфигурации надо создать по 3 файла
     * с расширениями: php, xml и yml.
     *
     * ВНИМАНИЕ! Перед использованием контейнер надо скомпилировать вызвав метод compile().
     *
     * @param string $configDir Путь к папке тестовой конфигурации.
     * @param string $file      Имя файла (баз расширения).
     * @param string $type      Тип файла (xml, yml или php).
     *
     * @throws \Throwable
     *
     * @since 0.3.0 Тип возвращаемого значения изменён с ContainerBuilder на TestContainerBuilder.
     * @since 0.1
     */
    protected function createContainerFromFile(
        string $configDir,
        string $file,
        string $type
    ): TestContainerBuilder {
        $container = $this->createContainer();
        $locator = new FileLocator($configDir);

        switch ($type) {
            case 'xml':
                $loader = new XmlFileLoader($container, $locator);
                break;

            case 'yml':
                $loader = new YamlFileLoader($container, $locator);
                break;

            case 'php':
                $loader = new PhpFileLoader($container, $locator);
                break;

            default:
                throw new \LogicException(
                    sprintf('Неподдерживаемый формат конфигурационных файлов: «%s».', $type)
                );
        }

        $loader->load($file . '.' . $type);

        $this->containers[] = $container;

        return $container;
    }

    /**
     * Задаёт ожидание наличия зависимости в контейнере
     *
     * Метод следует вызывать до ContainerBuilder::compile().
     *
     * @param string           $id        Идентификатор ожидаемой зависимости.
     * @param ContainerBuilder $container Конструктор контейнера.
     *
     * @throws \Throwable
     *
     * @deprecated 0.3.0 Используйте {@see TestContainerBuilder::expectDefinitionsExists()}.
     * @since      0.1
     */
    protected function expectServiceExists(string $id, ContainerBuilder $container): void
    {
        \trigger_error('Использование устаревшего метода ' . __METHOD__, \E_USER_DEPRECATED);

        if ($container instanceof TestContainerBuilder) {
            $container->expectDefinitionsExists($id);
        } else {
            if (!$container->hasDefinition(ContainerExpectations::class)) {
                $definition = new Definition(ContainerExpectations::class);
                $definition->setPublic(true);
                $definition->setAutowired(false);
                $container->setDefinition(ContainerExpectations::class, $definition);
            }

            $definition = $container->getDefinition(ContainerExpectations::class);
            $definition->addMethodCall('assertDependencyExists', [new Reference($id)]);
        }
    }

    /**
     * Возвращает имя главного класса проверяемого пакета
     *
     * @throws \LogicException
     *
     * @since 0.1
     */
    protected function getBundleClass(): string
    {
        $parts = explode('\\', get_class($this));
        $bundleClassName = $parts[0] . '\\' . $parts[1] . '\\' . $parts[0] . $parts[1];

        if (!\class_exists($bundleClassName)) {
            throw new \LogicException(
                \sprintf(
                    'Не удалось определить имя главного класса проверяемого пакета — класс «%s» не найден.'
                    . ' Вы можете переопределить метод %s чтобы он возвращал правильное имя класса.',
                    $bundleClassName,
                    __METHOD__
                )
            );
        }

        return $bundleClassName;
    }

    /**
     * Извлекает службу из локатора после компиляции контейнера
     *
     * Предварительно, до компиляции, служба должна быть добавлена методом {@see addToLocator()}.
     *
     * @param string             $serviceId Идентификатор службы.
     * @param ContainerInterface $container Скомпилированный контейнер.
     *
     * @return mixed
     *
     * @throws \Throwable
     *
     * @deprecated 0.3.0 Используйте {@see TestContainerBuilder::locate()}.
     * @since      0.1
     */
    protected function getFromLocator(string $serviceId, ContainerInterface $container)
    {
        \trigger_error('Использование устаревшего метода ' . __METHOD__, \E_USER_DEPRECATED);

        if ($container instanceof TestContainerBuilder) {
            return $container->locate($serviceId);
        }

        $locator = $container->get('test_locator');
        self::assertInstanceOf(ServiceLocator::class, $locator);

        return $locator->get($serviceId);
    }

    /**
     * Возвращает список требуемых пакетов
     *
     * @return array<string> Имена главных классов пакетов, например, «[FooBundle::class]».
     *
     * @since 0.1
     */
    protected function getRequiredBundles(): array
    {
        return [FrameworkBundle::class];
    }

    /**
     * Возвращает путь к папке для размещения временных файлов
     *
     * @since 0.1
     */
    protected function getTempDir(): string
    {
        return sys_get_temp_dir();
    }

    /**
     * Загружает расширение контейнера
     *
     * @param ContainerBuilder   $container Конструктор контейнера.
     * @param ExtensionInterface $extension Загружаемое расширение.
     * @param array<mixed>       $config    Конфигурация расширения.
     *
     * @throws \Throwable
     *
     * @deprecated 0.3.0 Вместо этого метода используйте {@see TestContainerBuilder::loadExtension()}.
     * @since      0.1
     */
    protected function loadExtension(
        ContainerBuilder $container,
        ExtensionInterface $extension,
        array $config = []
    ): void {
        \trigger_error('Использование устаревшего метода ' . __METHOD__, \E_USER_DEPRECATED);

        if ($container instanceof TestContainerBuilder) {
            $container->loadExtension($extension, $config);
        } else {
            $container->registerExtension($extension);
            $container->loadFromExtension($extension->getAlias(), $config);
        }
    }

    /**
     * Загружает в контейнер произвольный пакет Symfony
     *
     * @param ContainerBuilder $container       Конструктор контейнера.
     * @param string           $bundleClassName Имя класса пакета.
     * @param array<mixed>     $config          Конфигурация расширения.
     *
     * @throws \Throwable
     *
     * @deprecated 0.3.0 Вместо этого метода используйте {@see
     *             TestContainerBuilder::registerBundle()}.
     * @since      0.1
     */
    protected function registerBundle(
        ContainerBuilder $container,
        string $bundleClassName,
        array $config = []
    ): void {
        \trigger_error('Использование устаревшего метода ' . __METHOD__, \E_USER_DEPRECATED);

        if ($container instanceof TestContainerBuilder) {
            $container->registerBundle($bundleClassName, $config);
        } else {
            $bundle = new $bundleClassName();
            if (!$bundle instanceof BundleInterface) {
                throw new ExpectationFailedException(
                    sprintf('Класс «%s» не является пакетом Symfony.', $bundleClassName)
                );
            }
            $bundle->build($container);

            $extension = $bundle->getContainerExtension();
            if ($extension !== null) {
                $this->loadExtension($container, $extension, $config);
            }
        }
    }

    /**
     * Очищает окружение после завершения теста
     *
     * @throws \Throwable
     */
    protected function tearDown(): void
    {
        foreach ($this->containers as $container) {
            if ($container->has(ContainerExpectations::class)) {
                self::assertNotNull($container->get(ContainerExpectations::class));
            }
        }

        parent::tearDown();
    }
}
