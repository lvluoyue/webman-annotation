<?php

/**
 * Created by PhpStorm.
 * User: LinFei
 * Created time 2022/10/21 21:08:44
 * E-mail: fly@eyabc.cn
 */
declare (strict_types=1);

namespace LinFly\Annotation\Util;

use Closure;
use FilesystemIterator;
use Generator;
use LinFly\Annotation\Bootstrap\AnnotationBootstrap;
use PhpToken;
use SplFileInfo;

abstract class AnnotationUtil
{
    /**
     * 排除路径转正则表达式
     * @access public
     * @param array $exclude
     * @return string
     */
    public static function excludeToRegular(array $exclude): string
    {
        $regular = '';

        foreach ($exclude as $value) {
            // 绝对路径开始的不拼接base路径
            if (!str_starts_with($value, '/')) {
                $value = self::basePath($value);
            }
            $value = preg_quote($value);
            $value = str_replace(['/', '\*'], ['\/', '.*'], $value);
            $regular .= $value . ')|(';
        }

        return substr($regular, 0, -3);
    }

    /**
     * 通过目录查找文件
     * @access public
     * @param string $path
     * @param Closure $filter
     * @return Generator
     */
    public static function findDirectory(string $path, Closure $filter): Generator
    {
        if (str_contains($path, '*')) { // 通配符查找
            $iterator = glob($path);
        } else { // 按实际路径查找
            $iterator = new FilesystemIterator($path);
        }

        /** @var SplFileInfo|string $item */
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                $item = new SplFileInfo($item);
            }
            if ($item->isDir() && !$item->isLink()) {
                yield from self::findDirectory($item->getPathname(), $filter);
            } else {
                if ($filter($item)) {
                    yield $item;
                }
            }
        }
    }

    /**
     * 替换路径分隔符
     * @access public
     * @param string $path
     * @return string
     */
    public static function replaceSeparator(string $path): string
    {
        return str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * 获取根目录路径
     * @access public
     * @param string $path
     * @return string
     */
    public static function basePath(string $path = ''): string
    {
        $path = base_path($path);
        return self::replaceSeparator($path);
    }

    /**
     * 校验一个路径是否在允许的路径内
     * @param string $pathname
     * @return bool
     */
    public static function isInAllowedPath(string $pathname): bool
    {
        if (empty(AnnotationBootstrap::$config['include_paths'])) {
            return true;
        }
        return (bool)preg_match(AnnotationBootstrap::$config['include_regex_paths'], $pathname);
    }

    /**
     * 获取文件中的所有类
     * @param string $file
     * @return array
     */
    public static function getAllClassesInFile(string $file)
    {
        $classes = [];
        $tokens = PhpToken::tokenize(file_get_contents($file));
        $count = count($tokens);
        $tNamespaceTokens = [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED];
        $tClassTokens = [T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT];

        $namespace = '';
        for ($i = 2; $i < $count; $i++) {
            // 扫描到命名空间
            if ($tokens[$i]->is(T_NAMESPACE)) {
                $namespace = '';
                $tempNamespace = '';

                // 跳过空白和分号
                while (++$i < $count && $tokens[$i]->is([T_WHITESPACE, '{', ';'])) {
                    continue;
                }

                if ($tokens[$i]->is($tNamespaceTokens)) {
                    $tempNamespace .= ($tempNamespace !== '' ? '\\' : '') . $tokens[$i]->text;
                }

                $namespace = $tempNamespace;
            }

            // 扫描到类、接口、枚举或特性
            if ($tokens[$i]->is($tClassTokens)) {
                // 跳过空白和注释
                while (++$i < $count && $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }

                if ($tokens[$i]->is(T_STRING)) {
                    $className = $tokens[$i]->text;
                    $classes[] = ($namespace ? $namespace . '\\' : '') . $className;
                }
            }
        }
        return $classes;
    }
}
