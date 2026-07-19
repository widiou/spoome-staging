<?php

declare(strict_types=1);

namespace Spoome\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spoome\Support\Str;

final class StrTest extends TestCase
{
    public function testHandleKeepsHyphens(): void
    {
        // Policy scelta: handle hyphen-friendly (stile LinkedIn) — i trattini NON diventano underscore.
        $this->assertSame('marco-rossi', Str::handle('Marco Rossi'));
        $this->assertSame('marco-rossi', Str::handle('marco-rossi'));
    }

    public function testHandleLowercasesAndTrims(): void
    {
        $this->assertStringNotContainsString(' ', Str::handle('  Giulia   Bianchi  '));
        $this->assertSame(Str::handle('Giulia Bianchi'), strtolower(Str::handle('Giulia Bianchi')));
    }

    public function testHandleMaxLength(): void
    {
        $this->assertLessThanOrEqual(30, strlen(Str::handle(str_repeat('a', 100))));
    }

    public function testHandleNeverStartsOrEndsWithHyphen(): void
    {
        $h = Str::handle('-- ciao --');
        $this->assertStringStartsNotWith('-', $h);
        $this->assertStringEndsNotWith('-', $h);
    }
}
