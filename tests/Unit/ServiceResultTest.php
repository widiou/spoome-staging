<?php

declare(strict_types=1);

namespace Spoome\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spoome\Core\ServiceResult;

final class ServiceResultTest extends TestCase
{
    public function testOk(): void
    {
        $r = ServiceResult::ok(['id' => 1], ['message' => 'fatto'], 201);
        $this->assertTrue($r->ok);
        $this->assertSame(['id' => 1], $r->data);
        $this->assertSame('fatto', $r->meta['message']);
        $this->assertSame(201, $r->code);
        $this->assertNull($r->error);
    }

    public function testFail(): void
    {
        $r = ServiceResult::fail('errore', 422, ['email' => 'non valida']);
        $this->assertFalse($r->ok);
        $this->assertSame('errore', $r->error);
        $this->assertSame(422, $r->code);
        $this->assertSame(['email' => 'non valida'], $r->errors);
        $this->assertNull($r->data);
    }

    public function testNoContent(): void
    {
        $r = ServiceResult::noContent();
        $this->assertTrue($r->ok);
        $this->assertSame(204, $r->code);
    }
}
