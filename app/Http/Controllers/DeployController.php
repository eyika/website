<?php

namespace App\Http\Controllers;

use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Facade\JsonResponse; // facade → static ok()/unauthorized()/serverError()

/**
 * Receives GitHub push webhooks and requests a redeploy.
 *
 * serv00 disables PHP's exec/shell_exec, so this endpoint does NOT run git/composer itself. It
 * verifies the webhook's HMAC signature and, for a push to main, drops a flag file. A serv00
 * cron job (deploy.sh) runs in a real shell, sees the flag, and does the actual
 * `git pull` + `composer install` + `vendor:publish`. This also keeps the web request fast.
 *
 * Configure on GitHub (repo → Settings → Webhooks):
 *   Payload URL : https://basttyydev.serv00.net/deploy/github
 *   Content type: application/json
 *   Secret      : same value as GITHUB_WEBHOOK_SECRET in .env
 *   Events      : Just the push event
 */
class DeployController extends Controller
{
    public function github(Request $request)
    {
        $secret = (string) env('GITHUB_WEBHOOK_SECRET', '');
        if ($secret === '') {
            return JsonResponse::serverError('deploy webhook is not configured');
        }

        // Verify the signature against the RAW body — GitHub signs the exact bytes it sent.
        // Use Request::rawBody(): php://input is a one-shot stream the framework already
        // consumed and cached, so a fresh file_get_contents('php://input') would be empty.
        $raw = $request->rawBody();
        $signature = (string) $request->headers('X-Hub-Signature-256');
        $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);

        if ($signature === '' || !hash_equals($expected, $signature)) {
            return JsonResponse::unauthorized('invalid signature');
        }

        $event = (string) $request->headers('X-GitHub-Event');
        if ($event === 'ping') {
            return JsonResponse::ok('pong'); // GitHub's test delivery
        }
        if ($event !== 'push') {
            return JsonResponse::ok('ignored event', ['event' => $event]);
        }

        $payload = json_decode($raw, true) ?: [];
        if (($payload['ref'] ?? '') !== 'refs/heads/main') {
            return JsonResponse::ok('ignored ref', ['ref' => $payload['ref'] ?? null]);
        }

        // Signal the cron deployer. Path is resolved from THIS file (<app>/app/Http/Controllers)
        // so it never depends on how the domain's document root is configured.
        $flag = dirname(__DIR__, 3) . '/storage/deploy.request';
        @file_put_contents($flag, ($payload['after'] ?? 'HEAD') . "\n");

        return JsonResponse::ok('deploy queued', ['commit' => $payload['after'] ?? null]);
    }
}
