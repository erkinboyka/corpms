<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\RedactContextProcessor;
use Monolog\DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/** TEST-10: ПДн и секреты не должны попадать в структурированный контекст логов. */
final class LogRedactionTest extends TestCase
{
    private function process(array $context): LogRecord
    {
        $record = new LogRecord(
            new DateTimeImmutable(true),
            'test',
            Level::Info,
            'Тестовое событие',
            $context,
        );

        return (new RedactContextProcessor)($record);
    }

    public function test_personal_data_and_secrets_are_replaced(): void
    {
        $record = $this->process([
            'employee_id' => 42,
            'user_id' => 7,
            'email' => 'ivanov@example.test',
            'phone' => '+7 999 000-00-00',
            'api_token' => 'plain-secret-value',
            'password' => 'plain-secret-value',
            'corpms.telegram.bot_token' => 'plain-secret-value',
            'nested' => ['fio' => 'Иванов Иван', 'safe' => 'ok'],
            'jwt' => 'eyJhbGciOiJIUzI1NiJ9.payload',
        ]);

        foreach ([
            'employee_id', 'user_id', 'email', 'phone',
            'api_token', 'password', 'corpms.telegram.bot_token', 'jwt',
        ] as $key) {
            self::assertSame('[REDACTED]', $record->context[$key], $key.' должен быть вырезан');
        }
        self::assertSame('[REDACTED]', $record->context['nested']['fio']);
        self::assertSame('ok', $record->context['nested']['safe']);
    }

    public function test_correlation_id_survives_redaction(): void
    {
        $record = $this->process([
            'correlation_id' => 'req-01J9ZQ',
            'action' => 'ai.asked',
        ]);

        self::assertSame('req-01J9ZQ', $record->context['correlation_id']);
        self::assertSame('ai.asked', $record->context['action']);
    }

    public function test_all_file_channels_use_the_redaction_processor(): void
    {
        foreach (['single', 'daily', 'monthly', 'stderr', 'papertrail', 'syslog', 'errorlog'] as $channel) {
            $config = config('logging.channels.'.$channel);
            self::assertIsArray($config, $channel);
            self::assertContains(
                RedactContextProcessor::class,
                $config['processors'] ?? [],
                $channel.' должен вырезать ПДн',
            );
        }

        $source = (string) file_get_contents(config_path('logging.php'));
        self::assertStringContainsString("env('LOG_LEVEL', 'info')", $source);
    }
}
