<?php

/**
 * Created by PhpStorm.
 * User: LinFei
 * Created time 2022/10/11 11:05:35
 * E-mail: fly@eyabc.cn
 */
declare (strict_types=1);

namespace LinFly\Annotation;

use Closure;
use Throwable;
use Generator;
use SplFileInfo;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionAttribute;
use ReflectionException;
use ReflectionParameter;
use LinFly\Annotation\Annotation\Inherit;
use LinFly\Annotation\Util\AnnotationUtil;
use Doctrine\Common\Annotations\AnnotationReader;
use LinFly\Annotation\Interfaces\IAnnotationItem;
use LinFly\Annotation\Interfaces\IAnnotationHandle;

abstract class Annotation
{
    /**
     * 注解处理类
     * @var array
     */
    protected static array $handle = [];

    /**
     * 注解类结果集
     * @var array
     */
    protected static array $annotations = [];

    /**
     * 注释解析器
     * @var AnnotationReader|null
     */
    protected static ?AnnotationReader $annotationReader = null;

    /**
     * 扫描注解类
     * @access public
     * @param array $include 扫描的路径
     * @param array $exclude 排除的路径
     * @return Generator
     */
    public static function scan(array $include, array $exclude = []): Generator
    {
        // 排除路径转正则表达式
        $regex = AnnotationUtil::excludeToRegular($exclude);
        $excludeRegex = $regex ? '/^(' . $regex . ')/' : '';

        foreach ($include as $path) {
            // 扫描绝对路径
            $path = AnnotationUtil::basePath($path);
            // 扫描目录
            yield from AnnotationUtil::findDirectory($path, function (SplFileInfo $item) use ($excludeRegex) {
                return $item->getExtension() === 'php' && !($excludeRegex && preg_match($excludeRegex, $item->getPathname()));
            });
        }
    }

    /**
     * 解析注解
     * @access public
     * @param Generator $generator
     * @return void
     * @throws ReflectionException
     */
    public static function parseAnnotations(Generator $generator): void
    {
        /** @var SplFileInfo $item */
        foreach ($generator as $item) {
            // 获取路径中的类名地址
            $pathname = $item->getPathname();
            // 获取文件中的所有类
            $classes = AnnotationUtil::getAllClassesInFile($pathname);

            // 如果文件中有多个类就引入文件，因为命名不规范 Composer 无法自动加载会导致反射失败
            if (isset($classes[1])) {
                require_once $pathname;
            }

            foreach ($classes as $class) {
                try {
                    // 反射类
                    $reflection = new ReflectionClass($class);
                } catch (Throwable $e) {
                    AnnotationUtil::output('[AnnotationScan] ERROR: ' . $e->getMessage());
                    continue;
                }

                // 解析类的注解
                foreach (self::yieldParseClassAnnotations($reflection) as $annotations) {
                    // 遍历注解结果集
                    foreach ($annotations as $item) {
                        // 注解类
                        $annotationClass = $item['annotation'];
                        // 调用注解处理类
                        if (isset(self::$handle[$annotationClass])) {
                            /** @var IAnnotationHandle $handle */
                            foreach (self::$handle[$annotationClass] as $handle) {
                                [$handle, 'handle']($item, $class);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * 解析类注解 包括：类注解、属性注解、方法注解、方法参数注解
     * @access public
     * @param string|ReflectionClass $className
     * @return array
     * @throws ReflectionException
     */
    public static function parseClassAnnotations(string|ReflectionClass $className): array
    {
        $reflectionClass = is_string($className) ? new ReflectionClass($className) : $className;

        $methods = $properties = [];

        // 获取类的注解
        $class = self::getClassAnnotations($reflectionClass);
        // 获取所有方法的注解
        foreach ($reflectionClass->getMethods() as $reflectionMethod) {
            // 获取方法注解
            $method = self::getMethodAnnotations($reflectionMethod);
            // 获取方法参数注解
            $parameters = [];
            // 获取方法参数的注解
            foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
                $parameter = self::getMethodParameterAnnotations($reflectionMethod, $reflectionParameter);
                $parameter && ($parameters[$reflectionParameter->name] = $parameter);
            }
            // 跳过空数据
            if (empty($method) && empty($parameters)) {
                continue;
            }

            $methods[$reflectionMethod->name] = [
                // 方法注解
                'methods' => $method,
                // 方法参数注解
                'parameters' => $parameters,
            ];
        }
        // 获取所有属性的注解
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            $property = self::getPropertyAnnotations($reflectionClass, $reflectionProperty);
            $property && ($properties[$reflectionProperty->name] = $property);
        }

        return ['class' => $class, 'method' => $methods, 'property' => $properties];
    }

    /**
     * 解析类注解 包括：类注解、属性注解、方法注解、方法参数注解，利用Generator提高性能
     * @access public
     * @param string|ReflectionClass $className
     * @return Generator
     * @throws ReflectionException
     */
    public static function yieldParseClassAnnotations(string|ReflectionClass $className): Generator
    {
        $reflectionClass = is_string($className) ? new ReflectionClass($className) : $className;

        // 获取类的注解
        yield from self::getClassAnnotations($reflectionClass);
        // 获取所有方法的注解
        foreach ($reflectionClass->getMethods() as $reflectionMethod) {
            // 获取方法注解
            $method = self::getMethodAnnotations($reflectionMethod);
            $method && (yield from $method);
            // 获取方法参数的注解
            foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
                $parameter = self::getMethodParameterAnnotations($reflectionMethod, $reflectionParameter);
                $parameter && (yield from $parameter);
            }
        }
        // 获取所有属性的注解
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            $property = self::getPropertyAnnotations($reflectionClass, $reflectionProperty);
            $property && (yield from $property);
        }
    }

    /**
     * 获取类注解
     * @access public
     * @param string|ReflectionClass $className
     * @param array|string $scanAnnotations
     * @return array
     * @throws ReflectionException
     */
    public static function getClassAnnotations(string|ReflectionClass $className, array|string $scanAnnotations = []): array
    {
        $scanAnnotations = (array)$scanAnnotations;

        $reflection = is_string($className) ? new ReflectionClass($className) : $className;

        $annotations = self::cache($reflection->getName(), 'class', function () use ($className, $reflection) {
            // 扫描PHP8原生注解
            $attributes = $reflection->getAttributes();
            // 通过注释解析为注解
            $readerAttributes = self::getAnnotationReader()->getClassAnnotations($reflection);

            return self::buildScanAnnotationItems([...$attributes, ...$readerAttributes], [
                'type' => 'class',
                // 类名
                'class' => $reflection->name,
            ], function (array &$annotations) use ($reflection) {
                // 获取父类
                $parentClass = $reflection->getParentClass();
                // 没有父类 或者 父类不在扫描范围内则跳过
                if (
                    false === $parentClass ||
                    !AnnotationUtil::isInAllowedPath((string)$parentClass->getFileName())
                ) {
                    return;
                }

                // 获取父类注解列表
                $parentAnnotations = self::getClassAnnotations($parentClass);
                // 处理注解继承
                self::handleAnnotationInherit($annotations, $parentAnnotations, ['class' => $reflection->name]);
            });
        });

        return self::filterScanAnnotations($annotations, $scanAnnotations);
    }

    /**
     * 获取类方法注解
     * @access public
     * @param string|ReflectionMethod $methodName
     * @param array|string $scanAnnotations
     * @return array
     * @throws ReflectionException
     */
    public static function getMethodAnnotations(string|ReflectionMethod $methodName, array|string $scanAnnotations = []): array
    {
        $scanAnnotations = (array)$scanAnnotations;

        $reflectionMethod = is_string($methodName) ? new ReflectionMethod($methodName) : $methodName;
        // 类.方法名 标签
        $tag = 'method.' . $reflectionMethod->name;

        $annotations = self::cache($reflectionMethod->class, $tag, function () use ($reflectionMethod) {
            // 扫描PHP8原生注解
            $attributes = $reflectionMethod->getAttributes();
            // 通过注释解析为注解
            $readerAttributes = self::getAnnotationReader()->getMethodAnnotations($reflectionMethod);

            return self::buildScanAnnotationItems([...$attributes, ...$readerAttributes], [
                'type' => 'method',
                // 类名
                'class' => $reflectionMethod->class,
                // 方法名
                'method' => $reflectionMethod->name,
            ], function (array &$annotations) use ($reflectionMethod) {
                try {
                    // 父类不在扫描范围内则跳过
                    if (!AnnotationUtil::isInAllowedPath((string)$reflectionMethod->getFileName())) {
                        return;
                    }
                    // 获取方法原型
                    $parentMethod = $reflectionMethod->getPrototype();
                    // 获取方法原型注解列表
                    $parentAnnotations = self::getMethodAnnotations($parentMethod);
                    // 处理注解继承
                    self::handleAnnotationInherit($annotations, $parentAnnotations, [
                        'class' => $reflectionMethod->class,
                    ]);
                } catch (ReflectionException) {
                    return;
                }
            });
        });

        return self::filterScanAnnotations($annotations, $scanAnnotations);
    }

    /**
     * 获取类方法注解
     * @access public
     * @param string|ReflectionClass $className
     * @param string|ReflectionProperty $propertyName
     * @param array|string $scanAnnotations
     * @return array
     * @throws ReflectionException
     */
    public static function getPropertyAnnotations(string|ReflectionClass $className, string|ReflectionProperty $propertyName, array|string $scanAnnotations = []): array
    {
        $scanAnnotations = (array)$scanAnnotations;

        $reflectionClass = is_string($className) ? new ReflectionClass($className) : $className;
        $reflectionProperty = is_string($propertyName) ? new ReflectionProperty($reflectionClass, $propertyName) : $propertyName;
        // 类.属性名 标签
        $tag = 'property.' . $reflectionProperty->name;

        $annotations = self::cache($reflectionClass->name, $tag, function () use ($reflectionProperty) {
            // 扫描PHP8原生注解
            $attributes = $reflectionProperty->getAttributes();
            // 通过注释解析为注解
            $readerAttributes = self::getAnnotationReader()->getPropertyAnnotations($reflectionProperty);

            return self::buildScanAnnotationItems([...$attributes, ...$readerAttributes], [
                'type' => 'property',
                // 类名
                'class' => $reflectionProperty->class,
                // 属性名
                'property' => $reflectionProperty->name,
            ], function (array &$annotations) use ($reflectionProperty) {
                try {
                    $parentClass = $reflectionProperty->getDeclaringClass()->getParentClass();
                    // 没有父类 或者 父类不在扫描范围内则跳过
                    if (
                        false === $parentClass ||
                        !AnnotationUtil::isInAllowedPath((string)$parentClass->getFileName())
                    ) {
                        return;
                    }
                    // 获取父类的当前属性
                    $parentProperty = $parentClass->getProperty($reflectionProperty->name);
                    // 获取方法原型注解列表
                    $parentAnnotations = self::getPropertyAnnotations($parentClass, $parentProperty);
                    // 处理注解继承
                    self::handleAnnotationInherit($annotations, $parentAnnotations, [
                        'class' => $parentClass->name,
                    ]);
                } catch (ReflectionException) {
                    return;
                }
            });
        });

        return self::filterScanAnnotations($annotations, $scanAnnotations);
    }

    /**
     * 获取方法参数注解
     * @access public
     * @param string|ReflectionMethod $methodName
     * @param string|ReflectionParameter $parameterName
     * @param array|string $scanAnnotations
     * @return array
     * @throws ReflectionException
     */
    public static function getMethodParameterAnnotations(string|ReflectionMethod $methodName, string|ReflectionParameter $parameterName, array|string $scanAnnotations = []): array
    {
        $scanAnnotations = (array)$scanAnnotations;
        $reflectionMethod = is_string($methodName) ? new ReflectionMethod($methodName) : $methodName;

        // 解析反射的参数
        $reflectionParameter = is_string($parameterName) ? new ReflectionParameter([
            // 类名
            $reflectionMethod->class,
            // 方法名
            $reflectionMethod->name,

        ], $parameterName) : $parameterName;

        $tag = 'parameter.' . $reflectionMethod->name . '.' . $reflectionParameter->name;

        $annotations = self::cache($reflectionMethod->class, $tag, function () use ($reflectionMethod, $reflectionParameter) {
            // 扫描PHP8原生注解
            $attributes = $reflectionParameter->getAttributes();

            return self::buildScanAnnotationItems($attributes, [
                'type' => 'parameter',
                // 类名
                'class' => $reflectionMethod->class,
                // 方法名
                'method' => $reflectionMethod->name,
                // 参数名
                'parameter_name' => $reflectionParameter->name,
            ]);
        });

        return self::filterScanAnnotations($annotations, $scanAnnotations);
    }

    /**
     * Build ScanAnnotationItems
     * @access public
     * @param array $attributes
     * @param array $parameters
     * @param Closure|null $inherit
     * @return array
     */
    protected static function buildScanAnnotationItems(array $attributes, array $parameters = [], \Closure $inherit = null): array
    {
        $annotations = [];

        foreach ($attributes as $attribute) {

            if ($attribute instanceof ReflectionAttribute) {
                // 获取注解类实例
                /** @var IAnnotationItem $annotation */
                $annotation = self::reflectionAttributeToAnnotation($attribute);
            } else {
                $annotation = $attribute;
            }

            if (!$annotation instanceof IAnnotationItem) {
                continue;
            }

            $annotations[$annotation::class][] = array_merge([
                // 注解参数类
                'annotation' => $annotation::class,
                // 注解传入的参数
                'arguments' => $annotation->getArguments(),
                // 注解所有的参数
                'parameters' => $annotation->getParameters(),
            ], $parameters);

            unset($annotation);
        }

        if ($inherit instanceof \Closure) {
            $inherit($annotations);
        }

        return $annotations;
    }

    /**
     * 处理注解继承
     * @param array $annotations
     * @param array $parentAnnotations
     * @param array $replaces
     * @return void
     */
    protected static function handleAnnotationInherit(array &$annotations, array $parentAnnotations, array $replaces = []): void
    {
        // 继承的注解参数
        $inherit = $annotations[Inherit::class][0]['parameters'] ?? false;

        // 子类使用了继承，设定不继承父类注解
        if ($inherit && $inherit['only'] === false) {
            return;
        }

        // 父类是否使用继承，父类使用继承，所有子类则都继承
        $parameter = $parentInherit = $parentAnnotations[Inherit::class][0]['parameters'] ?? false;

        // 子类父类都没用继承
        if (!$inherit && !$parentInherit) {
            return;
        }
        // 父类不使用继承
        if ($parentInherit && $parentInherit['only'] === false) {
            return;
        }

        // 子类使用了继承或者父类没使用继承，则设定继承父类注解
        if (!$parentInherit || $inherit) {
            $parameter = $inherit;
        }

        foreach ($parentAnnotations as $name => $annotation) {
            if ($name === Inherit::class) {
                // 父类使用了继承，子类没使用继承，子类则继承父类的Inherit注解
                false === $inherit && $annotations[$name] = $annotation;
                continue;
            }

            // 替换继承的注解参数
            if ($replaces) {
                $annotation = array_map(fn($item) => array_merge($item, $replaces), $annotation);
            }

            // 只继承父类的指定的注解
            if ($parameter['only'] && !in_array($name, $parameter['only'])) {
                continue;
            } // 只继承父类跟except参数不匹配的注解
            elseif ($parameter['except'] && in_array($name, $parameter['except'])) {
                continue;
            }
            // 合并或者覆盖所有注解
            $annotations[$name] = $parameter['merge'] ? array_merge(
                $annotation, $annotations[$name] ?? []
            ) : $annotation;
        }
    }

    /**
     * 注解解析缓存
     * @access public
     * @param string $className
     * @param string $tag
     * @param array|Closure|null $data
     * @return mixed
     */
    public static function cache(string $className, string $tag, array|Closure $data = null): mixed
    {
        if (is_null($data)) {
            return self::$annotations[$className][$tag] ?? false;
        }

        if ($data instanceof Closure) {
            return self::$annotations[$className][$tag] ??= $data();
        }

        self::$annotations[$className][$tag] ??= [];
        return self::$annotations[$className][$tag] = $data;
    }

    /**
     * 获取指定的ScanAnnotations
     * @access public
     * @param array $annotations
     * @param array $scanAnnotations
     * @return array
     */
    protected static function filterScanAnnotations(array $annotations, array $scanAnnotations): array
    {
        return $scanAnnotations ? array_filter($annotations, fn($key, $class) => in_array($class, $scanAnnotations)) : $annotations;
    }

    /**
     * 通过反射注解类获取注解类实例
     * @access public
     * @param ReflectionAttribute $attribute
     * @return mixed
     */
    protected static function reflectionAttributeToAnnotation(ReflectionAttribute $attribute)
    {
        $instance = $attribute->newInstance();
        return $instance->setArguments($attribute->getArguments());
    }

    /**
     * 获取注解处理类
     * @param string|null $annotation
     * @return array|string|null
     */
    public static function getHandle(string $annotation = null): array|string|null
    {
        return $annotation ? self::$handle[$annotation] ?? null : self::$handle;
    }

    /**
     * 添加注解处理类
     * @param string|array $annotationClass
     * @param string $handleClass
     * @return array
     */
    public static function addHandle(string|array $annotationClass, string $handleClass): array
    {
        if (is_array($annotationClass)) {
            foreach ($annotationClass as $annotation) {
                self::addHandle($annotation, $handleClass);
            }
            return self::$handle;
        }

        self::$handle[$annotationClass] ??= [];
        self::$handle[$annotationClass][] = $handleClass;
        return self::$handle;
    }

    /**
     * 移除注解处理类
     * @param string $annotationClass
     * @param string|null $handleClass
     * @return array
     */
    public static function removeHandle(string $annotationClass, string $handleClass = null): array
    {
        if ($handleClass) {
            $key = array_search($handleClass, self::$handle[$annotationClass] ?? []);
            if ($key !== false) {
                unset(self::$handle[$annotationClass][$key]);
            }
        } else {
            unset(self::$handle[$annotationClass]);
        }

        return self::$handle;
    }

    /**
     * 获取注释解析器
     * @access public
     * @return AnnotationReader
     */
    public static function getAnnotationReader(): AnnotationReader
    {
        if (is_null(self::$annotationReader)) {
            self::$annotationReader = new AnnotationReader();
        }
        return clone self::$annotationReader;
    }
}
