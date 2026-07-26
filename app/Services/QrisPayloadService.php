<?php

namespace App\Services;

class QrisPayloadService
{
    public function merchantName(string $payload): string
    {
        return trim((string) ($this->parse($payload)['59'] ?? ''));
    }

    public function validate(string $payload): bool
    {
        try {
            $fields = $this->parse($payload);
            $provided = strtoupper((string) ($fields['63'] ?? ''));
            $withoutCrc = substr($payload, 0, -4);

            return strlen($provided) === 4 && hash_equals($provided, $this->crc16($withoutCrc));
        } catch (\Throwable) {
            return false;
        }
    }

    private function parse(string $payload): array
    {
        $payload = trim($payload);

        if ($payload === '') {
            throw new \InvalidArgumentException('QRIS payload is empty');
        }

        $fields = [];
        $offset = 0;
        $totalLength = strlen($payload);

        while ($offset < $totalLength) {
            if ($offset + 4 > $totalLength) {
                throw new \InvalidArgumentException('Malformed QRIS payload');
            }

            $tag = substr($payload, $offset, 2);
            $lengthText = substr($payload, $offset + 2, 2);

            if (! ctype_digit($tag) || ! ctype_digit($lengthText)) {
                throw new \InvalidArgumentException('Malformed QRIS field');
            }

            $length = (int) $lengthText;
            $valueOffset = $offset + 4;

            if ($valueOffset + $length > $totalLength || array_key_exists($tag, $fields)) {
                throw new \InvalidArgumentException('Malformed QRIS field length');
            }

            $fields[$tag] = substr($payload, $valueOffset, $length);
            $offset = $valueOffset + $length;

            if ($tag === '63' && $offset !== $totalLength) {
                throw new \InvalidArgumentException('Unexpected data after QRIS checksum');
            }
        }

        return $fields;
    }

    private function crc16(string $payload): string
    {
        $crc = 0xFFFF;

        foreach (unpack('C*', $payload) as $byte) {
            $crc ^= $byte << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
