<?php

declare(strict_types = 1);

if (!function_exists('class_basename')) {
    function class_basename(object|string $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }
}
