<?php

namespace App\Http\Controllers;

use App\Services\OdooClient;
use App\Services\PhoneNumber;
use App\Services\SmsLedger;
use Illuminate\Support\Facades\Log;

class OdooPOS extends Controller
{
    /** Ledger key for the daily customer thank-you campaign. */
    private const CAMPAIGN = 'customer-daily';

    protected OdooClient $client;
    protected string $currency;

    public function __construct()
    {
        $this->client = new OdooClient(
            config('app.odoo_url'),
            config('app.odoo_db', ''),
            config('app.odoo_username'),
            config('app.odoo_password')
        );
        $this->currency = config('app.odoowoo_currency');
    }

    public function getDailySalesReport($recipients, $date)
    {
        $fields = array(
            'id',
            'name',
            'start_at',
            'cash_register_difference',
            'config_id',
            'total_payments_amount',
            'order_count'
        );

        try {
            $sessions = $this->searchReadAll('pos.session', $this->sessionCriteria($date), $fields);
        } catch (\Throwable $th) {
            Log::channel('sync')->error('Failed to fetch POS sessions', ['date' => $date, 'error' => $th->getMessage()]);
            throw $th;
        }

        $_sessions = [];
        if (!empty($sessions)) {
            $j = 0;
            foreach ($sessions as $session) {
                if ($session['total_payments_amount'] > 0) {
                    $fields = array(
                        'id',
                        'payment_method_id',
                        'amount'
                    );

                    $criteria = array(
                        array('session_id', '=', $session['id'])
                    );

                    try {
                        $payments = $this->searchReadAll('pos.payment', $criteria, $fields);
                    } catch (\Throwable $th) {
                        throw $th;
                    }

                    $_sessions[$j]['name'] = $this->formatText($session['config_id'][1]);
                    $_sessions[$j]['ref'] = $this->formatText($session['name']);
                    $_sessions[$j]['payment_methods'] = [];

                    foreach ($payments as $payment) {
                        $index = $payment['payment_method_id'][1];
                        $value = $payment['amount'];
                        if (!isset($_sessions[$j]['payment_methods'][$index])) {
                            $_sessions[$j]['payment_methods'][$index] = $value;
                        } else {
                            $_sessions[$j]['payment_methods'][$index] += $value;
                        }
                    }
                    $_sessions[$j]['count'] = $session['order_count'];
                    $_sessions[$j]['total'] = $session['total_payments_amount'];
                    $j++;
                }
            }
        }

        $messages = [];
        if (!empty($_sessions)) {
            foreach ($_sessions as $_session) {
                $messages[] = $this->messageTemplate1($_session);
            }
        }

        if (!empty($messages)) {
            $smsController = new SMSController();
            foreach ($messages as $message) {
                $result = $smsController->sendMessage(array_values($recipients), $message);

                if ($result['ok']) {
                    Log::channel('sms')->info('POS daily report sent', [
                        'date'       => $date,
                        'recipients' => count($recipients),
                    ]);
                } else {
                    Log::channel('sms')->error('POS daily report failed', [
                        'date'   => $date,
                        'code'   => $result['code'],
                        'detail' => $result['detail'],
                    ]);
                }
            }
        }
    }

    /**
     * Message every customer who bought at the till on $date.
     *
     * @param array{dry_run?: bool, limit?: int|null, retry_failed?: bool} $options
     * @return array<string, mixed> Run summary for the calling command to print.
     */
    public function getDailyCustomers(string $date, array $options = []): array
    {
        $dryRun      = (bool) ($options['dry_run'] ?? false);
        $limit       = $options['limit'] ?? null;
        $retryFailed = (bool) ($options['retry_failed'] ?? false);

        $summary = [
            'date'          => $date,
            'dry_run'       => $dryRun,
            'sessions'      => 0,
            'sessions_open' => 0,
            'orders'        => 0,
            'partners'      => 0,
            'no_phone'      => 0,
            'invalid_phone' => 0,
            'no_consent'    => 0,
            'already_sent'  => 0,
            'candidates'    => 0,
            'capped'        => 0,
            'sent'          => 0,
            'failed'        => 0,
        ];

        $sessions = $this->searchReadAll('pos.session', $this->sessionCriteria($date), ['id', 'name', 'stop_at']);
        $summary['sessions'] = count($sessions);

        // A session still open at run time holds orders this job will never
        // revisit — the date window only ever moves forward. Silence here is
        // what makes that loss unrecoverable, so it is reported instead.
        $open = $this->searchReadAll('pos.session', $this->sessionCriteria($date, false), ['id', 'name', 'state']);
        $summary['sessions_open'] = count($open);

        if ($open !== []) {
            Log::channel('sms')->warning('POS sessions from this date are not closed — their customers will not be messaged', [
                'date'     => $date,
                'sessions' => array_column($open, 'name'),
            ]);
        }

        if ($sessions === []) {
            return $summary;
        }

        // Accumulate across every session. The previous version assigned rather
        // than merged, so only the last register of the day survived the loop.
        $orders = [];
        foreach ($sessions as $session) {
            $criteria = array(
                array('session_id', '=', $session['id']),
                array('state', 'in', array('paid', 'done', 'invoiced'))
            );

            $orders = array_merge(
                $orders,
                $this->searchReadAll('pos.order', $criteria, ['id', 'partner_id'])
            );
        }

        $summary['orders'] = count($orders);

        // pos.order.partner_id already carries [id, name], so the per-order
        // res.partner round-trip the previous version made — each preceded by a
        // sleep — was only ever needed for the phone number. One batched read
        // replaces all of them.
        $names = [];
        foreach ($orders as $order) {
            $partner = $order['partner_id'] ?? false;

            if (!is_array($partner) || !isset($partner[0])) {
                continue;
            }

            $names[(int) $partner[0]] = is_string($partner[1] ?? null) ? $partner[1] : '';
        }

        $summary['partners'] = count($names);

        if ($names === []) {
            return $summary;
        }

        $consentField = trim((string) config('app.odoowoo_customer_sms_consent_field', ''));

        // Requesting a field the model does not have is a hard RPC error, not a
        // null — Odoo 19 dropped `mobile` from res.partner — so the list is
        // configuration rather than a hardcoded guess.
        $phoneFields = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('app.odoowoo_customer_sms_phone_fields', 'phone'))
        )));

        if ($phoneFields === []) {
            $phoneFields = ['phone'];
        }

        $fields = array_merge(['id', 'name'], $phoneFields);
        if ($consentField !== '') {
            $fields[] = $consentField;
        }

        $partners = $this->searchReadAll(
            'res.partner',
            [['id', 'in', array_keys($names)]],
            $fields
        );

        // A partner id that came off an order but no longer reads back has been
        // deleted or made inaccessible since. The old code indexed [0] on the
        // empty result and appended null, which surfaced downstream as a
        // message addressed to nobody; dropping it silently would instead leave
        // the counts unexplainable.
        if (count($partners) < count($names)) {
            $missing = array_diff(array_keys($names), array_map('intval', array_column($partners, 'id')));

            Log::channel('sms')->warning('Some POS customers could not be read back from Odoo — not messaged', [
                'date'        => $date,
                'partner_ids' => array_values($missing),
            ]);
        }

        $recipients = [];
        foreach ($partners as $partner) {
            $id = (int) ($partner['id'] ?? 0);

            // Older Odoo stores mobiles in either column depending on how the
            // contact was created, and the till usually fills `mobile`; reading
            // only `phone` dropped those customers with no trace.
            $raw = null;
            foreach ($phoneFields as $key) {
                if (!empty($partner[$key]) && is_string($partner[$key])) {
                    $raw = $partner[$key];
                    break;
                }
            }

            if ($raw === null) {
                $summary['no_phone']++;
                continue;
            }

            $msisdn = PhoneNumber::normalise($raw);

            if ($msisdn === null) {
                $summary['invalid_phone']++;
                Log::channel('sms')->warning('Unusable customer phone number — skipped', [
                    'partner_id' => $id,
                    'raw'        => $raw,
                ]);
                continue;
            }

            if ($consentField !== '' && empty($partner[$consentField])) {
                $summary['no_consent']++;
                continue;
            }

            // Keyed on the canonical number, so one person entered twice with
            // "0244..." and "+233244..." is billed and bothered once.
            if (!isset($recipients[$msisdn])) {
                $recipients[$msisdn] = [
                    'msisdn'     => $msisdn,
                    'partner_id' => $id,
                    'name'       => $partner['name'] ?? ($names[$id] ?? ''),
                ];
            }
        }

        // One locked read for the whole run rather than one per customer.
        $handled = SmsLedger::handledSet(self::CAMPAIGN, $date, $retryFailed);

        foreach ($recipients as $msisdn => $_ignored) {
            if (isset($handled[$msisdn])) {
                $summary['already_sent']++;
                unset($recipients[$msisdn]);
            }
        }

        $recipients = array_values($recipients);
        $summary['candidates'] = count($recipients);

        if (is_int($limit) && $limit > 0 && count($recipients) > $limit) {
            $summary['capped'] = count($recipients) - $limit;
            $recipients = array_slice($recipients, 0, $limit);

            // Never let a cap read as full coverage.
            Log::channel('sms')->notice('Recipient list capped by --limit', [
                'date'    => $date,
                'limit'   => $limit,
                'skipped' => $summary['capped'],
            ]);
        }

        if ($recipients === []) {
            return $summary;
        }

        $smsController = new SMSController();

        foreach ($recipients as $recipient) {
            $message = $this->messageTemplate2($recipient);

            if ($dryRun) {
                $summary['sent']++;
                Log::channel('sms')->info('DRY RUN — would send customer SMS', [
                    'date'       => $date,
                    'partner_id' => $recipient['partner_id'],
                    'msisdn'     => SmsLedger::mask($recipient['msisdn']),
                    'message'    => $message,
                ]);
                continue;
            }

            // Reserved before the call, so a crash mid-send cannot come back as
            // a duplicate on the next run. See App\Services\SmsLedger.
            SmsLedger::record(self::CAMPAIGN, $date, $recipient['msisdn'], SmsLedger::STATUS_SENDING, [
                'partner_id' => $recipient['partner_id'],
            ]);

            $result = $smsController->sendMessage([$recipient['msisdn']], $message);

            SmsLedger::record(
                self::CAMPAIGN,
                $date,
                $recipient['msisdn'],
                $result['ok'] ? SmsLedger::STATUS_SENT : SmsLedger::STATUS_FAILED,
                [
                    'partner_id' => $recipient['partner_id'],
                    'code'       => $result['code'],
                    'reference'  => $result['reference'],
                ]
            );

            if ($result['ok']) {
                $summary['sent']++;
                Log::channel('sms')->info('Customer SMS sent', [
                    'date'       => $date,
                    'partner_id' => $recipient['partner_id'],
                    'msisdn'     => SmsLedger::mask($recipient['msisdn']),
                    'code'       => $result['code'],
                    'reference'  => $result['reference'],
                ]);
            } else {
                $summary['failed']++;
                Log::channel('sms')->error('Customer SMS failed', [
                    'date'       => $date,
                    'partner_id' => $recipient['partner_id'],
                    'msisdn'     => SmsLedger::mask($recipient['msisdn']),
                    'code'       => $result['code'],
                    'detail'     => $result['detail'],
                    'rejected'   => $result['rejected'],
                ]);
            }
        }

        if (!$dryRun) {
            SmsLedger::prune((int) config('app.odoowoo_sms_ledger_days', 90));
        }

        return $summary;
    }

    /**
     * Domain selecting the POS sessions that belong to $date.
     *
     * Anchored on `start_at` alone. Bounding `stop_at` by the same day — as this
     * previously did — discarded every session cashed up after midnight, and
     * because the window only moves forward those customers were never messaged
     * at all.
     *
     * The dates are passed through as-is: wrapping them in date() treated the
     * value as a *format* string, which happened to round-trip only because
     * digits and punctuation are not format specifiers.
     *
     * @param bool $closed true for cashed-up sessions, false for the rest.
     */
    private function sessionCriteria(string $date, bool $closed = true): array
    {
        return array(
            array('start_at', '>=', $date . ' 00:00:00'),
            array('start_at', '<=', $date . ' 23:59:59'),
            array('state', $closed ? '=' : '!=', 'closed')
        );
    }

    /**
     * First usable given name, or a neutral fallback.
     *
     * Till operators routinely name a contact by their phone number, and the
     * old version stripped the digits and then took the now-empty first token —
     * addressing a paying customer as "Hey ,". Honorifics had the same effect
     * one word later ("Hey Mrs,").
     */
    private function formatName($inputString): string
    {
        $fallback = (string) config('app.odoowoo_customer_sms_fallback_name', 'there');

        if (!is_string($inputString)) {
            return $fallback;
        }

        $cleaned = preg_replace('/[0-9\p{P}]/u', ' ', $inputString);
        $titles  = ['mr', 'mrs', 'ms', 'miss', 'dr', 'prof', 'rev', 'sir', 'madam', 'mad'];

        foreach (preg_split('/\s+/', (string) $cleaned, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (!in_array(mb_strtolower($token), $titles, true)) {
                return $token;
            }
        }

        return $fallback;
    }

    private function formatText($input)
    {
        // Remove text within brackets and the brackets themselves
        $output = preg_replace('/\([^)]*\)/', '', $input);

        // Remove double spaces
        $output = preg_replace('/\s+/', ' ', $output);

        return trim($output);
    }

    private function messageTemplate1($data)
    {
        $message = $data['name'] . " ";
        $message .= "(" . $data['ref'] . ") on ";
        $message .= date("d M Y") . " ";
        $message .= "Report:\n";
        $message .= "Orders: " . $data['count'] . "\n";
        foreach ($data['payment_methods'] as $key => $value) {
            $message .= $key . ": " . $this->currency . " " . number_format($value, 2) . "\n";
        }
        $message .= "Total: " . $this->currency . " " . number_format($data['total'], 2);
        return $message;
    }

    private function messageTemplate2($data)
    {
        $message = config('app.odoowoo_customer_sms_template_1');
        $message = str_replace('[name]', ucwords(mb_strtolower($this->formatName($data['name']))), $message);
        $message = str_replace('[company]', config('app.odoowoo_company'), $message);
        return $message;
    }
}
