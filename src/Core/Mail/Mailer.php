<?php

declare(strict_types=1);

namespace TAW\Core\Mail;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mailer — fluent wrapper around wp_mail() with HTML template support.
 *
 * Usage:
 *   (new Mailer())
 *       ->to('user@example.com')
 *       ->subject('Welcome!')
 *       ->template('welcome')
 *       ->setVariables(['name' => 'Jane'])
 *       ->send();
 */
class Mailer
{
    private string $to;
    private string $subject;
    private ?MailTemplate $template = null;
    private string $fromName;
    private string $fromEmail;
    private array $headers = [];
    private array $attachments = [];

    public function __construct()
    {
        $this->fromName  = get_bloginfo('name');
        $this->fromEmail = get_option('admin_email');
    }

    public function to(string $email): self
    {
        $this->to = $email;
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function template(string $templateName): self
    {
        $this->template = new MailTemplate($templateName);
        return $this;
    }

    public function setVariables(array $variables): self
    {
        if (!$this->template) {
            throw new \RuntimeException('Call template() before setVariables().');
        }
        $this->template->setVariables($variables);
        return $this;
    }

    public function from(string $email, string $name = ''): self
    {
        $this->fromEmail = $email;
        if ($name) {
            $this->fromName = $name;
        }
        return $this;
    }

    public function addHeader(string $header): self
    {
        $this->headers[] = $header;
        return $this;
    }

    public function attach(string $filePath): self
    {
        $this->attachments[] = $filePath;
        return $this;
    }

    public function send(): bool
    {
        if (empty($this->to) || empty($this->subject) || !$this->template) {
            throw new \RuntimeException('Missing required mail parameters (to, subject, or template).');
        }

        $htmlBody = $this->template->compile();

        $headers = array_merge([
            'Content-Type: text/html; charset=UTF-8',
            "From: {$this->fromName} <{$this->fromEmail}>",
        ], $this->headers);

        return wp_mail($this->to, $this->subject, $htmlBody, $headers, $this->attachments);
    }
}
