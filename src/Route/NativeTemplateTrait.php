<?php
/*
 * MIT License
 *
 * Copyright (c) 2025 machinateur
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

declare(strict_types=1);

namespace Machinateur\RedisInfo\Route;

trait NativeTemplateTrait
{
    const TEMPLATE_PATH = __DIR__
    . \DIRECTORY_SEPARATOR . '..'
    . \DIRECTORY_SEPARATOR . '..'
    . \DIRECTORY_SEPARATOR . 'templates';

    public function render(string $template, array $context = []): string
    {
        $template = self::TEMPLATE_PATH . \DIRECTORY_SEPARATOR . \basename($template);
        if ( ! \file_exists($template)) {
            throw new \InvalidArgumentException(\sprintf('Template file "%s" not found', $template));
        }

        if (\pathinfo($template, \PATHINFO_EXTENSION) !== $extension = 'phtml') {
            throw new \InvalidArgumentException(\sprintf('Template file "%s" is not a .%s file', $template, $extension));
        }

        $scope = static function (string $template, array $context): ?string {
            empty($context) ?: \extract($context);

            \ob_start();
            @include $template;
            $output = \ob_get_clean();

            return $output;
        };

        unset($context['template']);
        $output = $scope($template, $context);

        return $output ?: '';
    }
}
