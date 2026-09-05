<?php
declare(strict_types=1);

namespace ImWiki\View;

final class View
{
    public function __construct(private readonly string $templateDir)
    {
    }

    public function render(string $template, array $data = [], string $layout = 'layout.php'): string
    {
        $templateFile = $this->templateDir . '/' . ltrim($template, '/');
        if (!is_file($templateFile)) {
            throw new \RuntimeException('Template not found.');
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $templateFile;
        $content = (string) ob_get_clean();

        $layoutFile = $this->templateDir . '/' . $layout;
        if (!is_file($layoutFile)) {
            return $content;
        }
        ob_start();
        require $layoutFile;
        return (string) ob_get_clean();
    }
}
