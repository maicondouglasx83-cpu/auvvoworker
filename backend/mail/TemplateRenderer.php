<?php
/**
 * Substitui {{chave}} no HTML; valores são escapados para uso em e-mail HTML.
 */
declare(strict_types=1);

final class TemplateRenderer
{
    public static function render(string $html, array $vars): string
    {
        $repl = [];
        foreach ($vars as $key => $value) {
            $k = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key);
            if ($k === '') {
                continue;
            }
            $repl['{{' . $k . '}}'] = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }
        return strtr($html, $repl);
    }

    public static function renderFile(string $absolutePath, array $vars): string
    {
        if (!is_readable($absolutePath)) {
            throw new InvalidArgumentException('Template não encontrado: ' . $absolutePath);
        }
        return self::render((string) file_get_contents($absolutePath), $vars);
    }

    /**
     * @param list<string> $required
     * @return list<string> chaves ausentes
     */
    public static function missingKeys(array $required, array $data): array
    {
        $missing = [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === '' || $data[$key] === null) {
                $missing[] = $key;
            }
        }
        return $missing;
    }
}
