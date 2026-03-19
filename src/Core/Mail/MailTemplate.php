<?php

declare(strict_types=1);

namespace TAW\Core\Mail;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MailTemplate — file-based HTML email template compiler.
 *
 * Looks for templates in the theme's `mails/` directory:
 *   mails/html/{name}.html  — pre-compiled HTML (production)
 *   mails/{name}.mjml       — MJML source (compiled at runtime in dev via spatie/mjml-php)
 *
 * The base directory can be overridden with the `taw_mail_templates_dir` filter:
 *
 *   add_filter('taw_mail_templates_dir', fn() => get_template_directory() . '/resources/mails');
 *
 * Variable replacement uses {{variable_name}} syntax.
 */
class MailTemplate
{
    private string $templateName;
    private string $mailsDir;
    private string $mailsDirHtml;
    private array $variables = [];

    public function __construct(string $templateName)
    {
        $this->templateName = $templateName;

        $baseDir = apply_filters('taw_mail_templates_dir', get_template_directory() . '/mails');
        $this->mailsDir     = rtrim($baseDir, '/');
        $this->mailsDirHtml = $this->mailsDir . '/html';

        $htmlPath = $this->mailsDirHtml . '/' . $templateName . '.html';
        $mjmlPath = $this->mailsDir     . '/' . $templateName . '.mjml';

        if (!file_exists($htmlPath) && !file_exists($mjmlPath)) {
            throw new \InvalidArgumentException("Mail template not found: {$templateName}");
        }
    }

    public function setVariable(string $key, mixed $value): self
    {
        $this->variables[$key] = $value;
        return $this;
    }

    public function setVariables(array $variables): self
    {
        $this->variables = array_merge($this->variables, $variables);
        return $this;
    }

    public function compile(): string
    {
        $htmlPath = $this->mailsDirHtml . '/' . $this->templateName . '.html';

        if (file_exists($htmlPath)) {
            $html = file_get_contents($htmlPath);
        } elseif (class_exists(\Spatie\Mjml\Mjml::class)) {
            // Dev fallback: compile MJML at runtime when no pre-compiled HTML exists.
            // Run `composer require --dev spatie/mjml-php` and compile locally before deploying.
            $mjmlPath = $this->mailsDir . '/' . $this->templateName . '.mjml';
            $mjmlContent = file_get_contents($mjmlPath);
            $html = \Spatie\Mjml\Mjml::new()->toHtml($mjmlContent);
        } else {
            throw new \RuntimeException(
                "Compiled HTML not found for template '{$this->templateName}'. " .
                "Run `mjml:compile` locally and deploy the generated HTML files."
            );
        }

        foreach ($this->variables as $key => $value) {
            $html = preg_replace('/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/', $value, $html);
        }

        return $html;
    }

    /**
     * List all available template names (from compiled HTML files).
     */
    public static function getAvailable(): array
    {
        $baseDir = apply_filters('taw_mail_templates_dir', get_template_directory() . '/mails');
        $htmlDir = rtrim($baseDir, '/') . '/html';
        $templates = [];

        if (!is_dir($htmlDir)) {
            return $templates;
        }

        foreach (scandir($htmlDir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'html') {
                $templates[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }

        return $templates;
    }
}
