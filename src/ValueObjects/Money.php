<?php

declare(strict_types=1);

namespace Ttpryg\OrderEngine\ValueObjects;

use InvalidArgumentException;

/**
 * Nilai moneter immutable yang menyimpan jumlah dalam satuan terkecil (minor units).
 *
 * Seluruh aritmetika dilakukan menggunakan integer sehingga bebas dari
 * floating-point precision error. Gunakan toDecimal() untuk persistensi
 * (kolom DECIMAL) dan presentation, atau minorUnits() untuk kebutuhan lain.
 */
final class Money
{
    private const SCALE = 2;

    private const FACTOR = 100;

    private function __construct(
        private readonly int $minorUnits,
        public readonly string $currency
    ) {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code: {$currency}");
        }
    }

    public static function ofMinor(int $minorUnits, string $currency = 'IDR'): self
    {
        return new self($minorUnits, $currency);
    }

    public static function fromDecimal(int|float|string $amount, string $currency = 'IDR'): self
    {
        if (is_int($amount)) {
            return new self($amount * self::FACTOR, $currency);
        }

        if (is_float($amount)) {
            if (! is_finite($amount)) {
                throw new InvalidArgumentException('Money amount must be a finite number.');
            }
            $amount = number_format($amount, self::SCALE + 4, '.', '');
        }

        return new self(self::toMinorUnits(trim($amount)), $currency);
    }

    /**
     * Mem-parsing nilai mentah dari layer persistensi (string decimal dari MySQL
     * atau float/int dari driver SQLite) menjadi representasi Money yang presisi.
     */
    public static function fromDatabaseValue(mixed $value, string $currency = 'IDR'): self
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('Unsupported money value type: '.get_debug_type($value));
        }

        return self::fromDecimal($value, $currency);
    }

    /**
     * Normalisasi nilai moneter dari berbagai bentuk input (Money, int, float, string).
     */
    public static function from(Money|int|float|string $amount, string $currency = 'IDR'): self
    {
        if ($amount instanceof self) {
            $amount->ensureCurrency($currency);

            return $amount;
        }

        return self::fromDecimal($amount, $currency);
    }

    public static function zero(string $currency = 'IDR'): self
    {
        return new self(0, $currency);
    }

    /**
     * Menentukan currency dari sekumpulan nilai input: currency dari nilai Money
     * pertama yang ditemukan, atau 'IDR' jika tidak ada nilai bertipe Money.
     */
    public static function resolveCurrency(Money|int|float|string|null ...$values): string
    {
        foreach ($values as $value) {
            if ($value instanceof self) {
                return $value->currency;
            }
        }

        return 'IDR';
    }

    public function add(self $other): self
    {
        $this->ensureSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->ensureSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function multiply(int|float $multiplier): self
    {
        if (is_float($multiplier)) {
            if (! is_finite($multiplier)) {
                throw new InvalidArgumentException('Multiplier must be a finite number.');
            }

            return new self((int) round($this->minorUnits * $multiplier), $this->currency);
        }

        return new self($this->minorUnits * $multiplier, $this->currency);
    }

    public function min(self $other): self
    {
        $this->ensureSameCurrency($other);

        return $this->minorUnits <= $other->minorUnits ? $this : $other;
    }

    public function max(self $other): self
    {
        $this->ensureSameCurrency($other);

        return $this->minorUnits >= $other->minorUnits ? $this : $other;
    }

    /**
     * Mengembalikan salinan dengan label currency berbeda tanpa mengubah jumlah.
     */
    public function withCurrency(string $currency): self
    {
        if ($this->currency === $currency) {
            return $this;
        }

        return new self($this->minorUnits, $currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->minorUnits === $other->minorUnits;
    }

    /**
     * @return int<-1, 1> -1 jika lebih kecil, 0 jika sama, 1 jika lebih besar
     */
    public function compareTo(self $other): int
    {
        $this->ensureSameCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    /**
     * Representasi decimal string dengan 2 digit desimal (mis. "12000000.00"),
     * aman untuk dipersistensikan ke kolom DECIMAL atau dikirim ke payment gateway.
     */
    public function toDecimal(): string
    {
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = abs($this->minorUnits);

        return sprintf(
            '%s%d.%02d',
            $sign,
            intdiv($absolute, self::FACTOR),
            $absolute % self::FACTOR
        );
    }

    /**
     * Konversi eksplisit ke float — hanya untuk display/logging, bukan untuk kalkulasi.
     */
    public function toFloat(): float
    {
        return (float) $this->toDecimal();
    }

    private function ensureSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot operate on different currencies: {$this->currency} and {$other->currency}");
        }
    }

    private function ensureCurrency(string $currency): void
    {
        if ($this->currency !== $currency) {
            throw new InvalidArgumentException("Expected money in {$currency}, got {$this->currency}");
        }
    }

    private static function toMinorUnits(string $amount): int
    {
        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $amount, $matches)
            || ($matches[2] === '' && ($matches[3] ?? '') === '')
        ) {
            throw new InvalidArgumentException("Invalid monetary value: {$amount}");
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = $matches[2] === '' ? '0' : $matches[2];
        $fraction = str_pad($matches[3] ?? '', 3, '0');
        $minorUnits = ((int) $whole) * self::FACTOR + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $minorUnits++;
        }

        return $sign * $minorUnits;
    }
}
