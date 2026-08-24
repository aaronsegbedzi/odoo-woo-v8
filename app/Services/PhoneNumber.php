<?php

namespace App\Services;

/**
 * Ghanaian MSISDN normalisation.
 *
 * Odoo contacts are typed by hand at the till, so the same customer's number
 * arrives as "0244123456", "+233 244 123 456", "233244123456" or
 * "244-123-456" depending on who keyed it. Sending on the raw string means
 * one person is billed for — and pestered by — up to four messages, because
 * de-duplication compares strings, not people.
 *
 * Everything is reduced to bare international form (233XXXXXXXXX, no plus),
 * which is what mNotify accepts and what makes de-duplication meaningful.
 * Anything that cannot be resolved to a plausible subscriber number returns
 * null rather than a best guess: a wrong number is a message delivered to a
 * stranger at the shop's expense, which is worse than a message not sent.
 */
class PhoneNumber
{
    /**
     * Canonical form of $raw, or null when it is not a usable Ghanaian number.
     *
     * @param string|null|false $raw Odoo returns false for an unset field.
     */
    public static function normalise($raw, ?string $countryCode = null): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $cc = $countryCode ?? (string) config('app.odoowoo_sms_country_code', '233');

        // Extensions ("0244123456 ext 12") and second numbers ("024.../027...")
        // would otherwise merge into one impossible 20-digit string.
        $raw = preg_split('/[\/,;]| ext\.? | x /i', trim($raw))[0] ?? '';

        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        // 00233... — the other way of writing +233.
        if (str_starts_with($digits, '00' . $cc)) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, $cc)) {
            $national = substr($digits, strlen($cc));
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } else {
            $national = $digits;
        }

        // Ghanaian subscriber numbers are nine digits after the trunk prefix.
        // Shorter is a truncated entry, longer is two numbers run together or a
        // stray extension — neither is safe to dial.
        if (strlen($national) !== 9) {
            return null;
        }

        // Mobile prefixes are 2x and 5x. Landlines (3x) cannot receive SMS, and
        // billing for them is money spent on guaranteed non-delivery.
        if (!preg_match('/^[25]\d{8}$/', $national)) {
            return null;
        }

        return $cc . $national;
    }
}
