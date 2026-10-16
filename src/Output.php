<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * What the tool prints.
 *
 * A class rather than `fwrite` calls so the tests can run the real command and
 * read its output, which is how the exit codes and the messages are asserted
 * rather than assumed.
 */
final class Output
{
    public function __construct(
        private $stdout = null,
        private $stderr = null,
    ) {
        $this->stdout ??= STDOUT;
        $this->stderr ??= STDERR;
    }

    /** In-memory output, for tests. */
    public static function capture(): self
    {
        $stream = fopen('php://memory', 'w+b');

        return new self($stream, $stream);
    }

    public function contents(): string
    {
        if (!is_resource($this->stdout)) {
            return '';
        }

        rewind($this->stdout);
        $contents = stream_get_contents($this->stdout);
        rewind($this->stdout);

        return $contents === false ? '' : $contents;
    }

    public function step(string $message): void
    {
        fwrite($this->stdout, '  ' . $message . "\n");
    }

    public function detail(string $message): void
    {
        fwrite($this->stdout, $message . "\n");
    }

    public function line(string $message): void
    {
        fwrite($this->stdout, $message . "\n");
    }

    public function error(string $message): void
    {
        fwrite($this->stderr, 'volucer-cli: ' . $message . "\n");
    }
}
