<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class SMSController extends Controller
{
    protected string $endPoint;
    protected string $apiKey;
    protected string $senderID;

    public function __construct()
    {
        $this->endPoint = (string) config('app.mnotify_api_url');
        $this->apiKey   = (string) config('app.mnotify_api_key');
        $this->senderID = (string) config('app.mnotify_sender_id');
    }

    /**
     * Send one message to one or more recipients.
     *
     * Returns a decided outcome rather than the raw body. The previous version
     * returned whatever curl_exec() produced — including `false` on a transport
     * failure — and the caller logged it unexamined, so a run in which every
     * message failed was indistinguishable in the log from a clean one.
     *
     * mNotify answers 200 with a body that carries the real verdict, so the
     * HTTP status alone is not enough; both are checked.
     *
     * @param  array<int, string> $recipients
     * @return array{ok: bool, status: int|null, code: string|null, detail: string|null, sent: int|null, rejected: int|null, reference: string|null, body: string|null}
     */
    public function sendMessage(array $recipients, string $message): array
    {
        if ($recipients === []) {
            return $this->failure(null, 'no_recipients', 'No recipients supplied', null);
        }

        if (trim($message) === '') {
            return $this->failure(null, 'empty_message', 'Refusing to send an empty message', null);
        }

        if ($this->endPoint === '' || $this->apiKey === '') {
            return $this->failure(null, 'not_configured', 'mNotify endpoint or API key is not configured', null);
        }

        $payload = [
            'recipient'     => array_values($recipients),
            'sender'        => $this->senderID,
            'message'       => $message,
            'is_schedule'   => 'false',
            'schedule_date' => '',
        ];

        try {
            $response = Http::withOptions([
                    // Deliberately retained: certificate verification stays off
                    // for this endpoint pending a separate decision. Note that
                    // the request carries the API key and customer numbers.
                    'verify'          => false,
                    // connectTimeout() does not exist on Laravel 8's client, so
                    // the Guzzle option is set directly. Without it, a gateway
                    // that accepts the socket but never answers stalls the whole
                    // run — and the run is a loop over every customer.
                    'connect_timeout' => (int) config('app.mnotify_connect_timeout', 10),
                ])
                ->timeout((int) config('app.mnotify_timeout', 15))
                ->retry(
                    max(1, (int) config('app.mnotify_retries', 3)),
                    max(0, (int) config('app.mnotify_retry_delay_ms', 1000)),
                    fn (Throwable $e) => $this->isRetryable($e)
                )
                ->acceptJson()
                ->asJson()
                ->post($this->endPoint . '?key=' . $this->apiKey, $payload);
        } catch (RequestException $e) {
            // With tries > 1 the client throws on an unsuccessful status once
            // the attempts are exhausted. The response is still attached, and
            // it carries the only useful diagnostics.
            return $this->fromResponse($e->response, false);
        } catch (ConnectionException $e) {
            return $this->failure(null, 'connection', $e->getMessage(), null);
        } catch (Throwable $e) {
            return $this->failure(null, 'transport', $e->getMessage(), null);
        }

        return $this->fromResponse($response, true);
    }

    /**
     * Decide the outcome from a response the gateway actually returned.
     */
    private function fromResponse(Response $response, bool $mayBeSuccessful): array
    {
        $body = $response->body();
        $json = $response->json();

        if (!$mayBeSuccessful || !$response->successful()) {
            return $this->failure(
                $response->status(),
                'http_' . $response->status(),
                $this->detailFrom($json),
                $body
            );
        }

        // A 200 with an unparseable body is not a delivery confirmation.
        if (!is_array($json)) {
            return $this->failure($response->status(), 'unparseable', 'Gateway returned a non-JSON body', $body);
        }

        $status = trim((string) ($json['status'] ?? $json['code'] ?? ''));

        // mNotify signals success as status "success" or code "2000".
        $ok = in_array(strtolower($status), ['success', 'ok', '2000'], true);

        // ...but an accepted *request* is not an accepted *number*. A number the
        // gateway will not take comes back as status "success" with the whole
        // batch in total_rejected, so the envelope alone would record a message
        // that was never sent. Observed live: total_sent 1 / total_rejected 0.
        $summary  = is_array($json['summary'] ?? null) ? $json['summary'] : [];
        $sent     = isset($summary['total_sent']) ? (int) $summary['total_sent'] : null;
        $rejected = isset($summary['total_rejected']) ? (int) $summary['total_rejected'] : null;

        if ($ok && ($sent === 0 || ($rejected !== null && $rejected > 0))) {
            $ok = false;
        }

        return [
            'ok'        => $ok,
            'status'    => $response->status(),
            'code'      => $status !== '' ? $status : null,
            'detail'    => $this->detailFrom($json),
            'sent'      => $sent,
            'rejected'  => $rejected,
            // The gateway's own id for this message — the reference to quote in
            // a billing or non-delivery query weeks later.
            'reference' => isset($summary['message_id']) && is_scalar($summary['message_id'])
                ? (string) $summary['message_id']
                : null,
            'body'      => $body,
        ];
    }

    /**
     * Retry only what a retry can plausibly fix.
     *
     * A rejected sender ID or a malformed number fails identically on every
     * attempt, so retrying it just triples the delay before the run moves on
     * to the next customer.
     */
    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /**
     * @param  mixed $json
     */
    private function detailFrom($json): ?string
    {
        if (!is_array($json)) {
            return null;
        }

        foreach (['message', 'msg', 'error', 'detail'] as $key) {
            if (isset($json[$key]) && is_scalar($json[$key])) {
                return (string) $json[$key];
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, status: int|null, code: string|null, detail: string|null, sent: int|null, rejected: int|null, reference: string|null, body: string|null}
     */
    private function failure(?int $status, string $code, ?string $detail, ?string $body): array
    {
        return [
            'ok'        => false,
            'status'    => $status,
            'code'      => $code,
            'detail'    => $detail,
            'sent'      => null,
            'rejected'  => null,
            'reference' => null,
            'body'      => $body,
        ];
    }
}
