<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Service\PickupMethodResolver;
use PixelPerfect\StorePickupInventory\Service\SourceCodeMapper;

class SourceCodeMapperTest extends TestCase
{
    private SourceCodeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new SourceCodeMapper(new PickupMethodResolver());
    }

    public function testValidSourceCodeIsUsedAsIs(): void
    {
        self::assertSame('vienna_1', $this->mapper->toLocationCode('vienna_1'));
    }

    public function testInvalidSourceCodeBecomesSPlusSixteenHexOfSha1(): void
    {
        $code = $this->mapper->toLocationCode('Vienna-1');

        self::assertSame('s' . substr(sha1('Vienna-1'), 0, 16), $code);
        self::assertMatchesRegularExpression('/^s[0-9a-f]{16}$/', $code);
    }

    public function testMappingIsStableAcrossCalls(): void
    {
        self::assertSame(
            $this->mapper->toLocationCode('Vienna-1'),
            (new SourceCodeMapper(new PickupMethodResolver()))->toLocationCode('Vienna-1')
        );
    }

    public function testDifferentInvalidCodesGetDifferentLocationCodes(): void
    {
        self::assertNotSame(
            $this->mapper->toLocationCode('Vienna-1'),
            $this->mapper->toLocationCode('Vienna-2')
        );
    }

    /**
     * @dataProvider sourceCodes
     */
    public function testOutputAlwaysPassesTheLocationCodeCheck(string $sourceCode): void
    {
        self::assertTrue(
            (new PickupMethodResolver())->isValidLocationCode($this->mapper->toLocationCode($sourceCode))
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sourceCodes(): array
    {
        return [
            'plain' => ['vienna_1'],
            'upper case' => ['VIENNA'],
            'dash' => ['vienna-1'],
            'umlaut' => ['wien_äöü'],
            'empty' => [''],
            'too long' => [str_repeat('a', 65)],
            'longest valid' => [str_repeat('a', 64)],
            'trailing line feed' => ["vienna\n"],
            'numeric' => ['7'],
        ];
    }

    public function testCodeOfMaximumLengthIsKeptButOneCharacterMoreIsHashed(): void
    {
        $max = str_repeat('a', 64);

        self::assertSame($max, $this->mapper->toLocationCode($max));
        self::assertNotSame($max . 'a', $this->mapper->toLocationCode($max . 'a'));
    }

    public function testAlgorithmAndLengthAreConstructorArguments(): void
    {
        $mapper = new SourceCodeMapper(new PickupMethodResolver(), 'md5', 8, 'x');

        self::assertSame('x' . substr(md5('Vienna-1'), 0, 8), $mapper->toLocationCode('Vienna-1'));
    }
}
