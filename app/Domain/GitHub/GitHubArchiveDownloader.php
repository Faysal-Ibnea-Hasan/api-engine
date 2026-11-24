<?php

namespace App\Domain\GitHub;

use App\Models\GitHubRepositorySource;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Streams the ZIP archive of one resolved commit straight to the quarantine disk.
 * HTTPS only; redirects (at most 2) only to the API host or an allowlisted archive host,
 * without the token; byte limits from Content-Length and from the bytes actually received.
 * The partial file is deleted after any failure. The archive is never opened here.
 */
final class GitHubArchiveDownloader
{
    private const MAX_REDIRECTS = 2;

    private const CHUNK = 65536;

    public function __construct(private readonly GitHubApiClient $client, private readonly GitHubInstallationTokens $tokens) {}

    public function download(GitHubRepositorySource $source, string $sha, string $target): void
    {
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw GitHubError::of('github.ref_not_found'); // only ever a resolved commit, never a branch name
        }

        $max = (int) config('sources.zip.max_upload_bytes'); // same limit as a ZIP upload
        $timeout = (int) config('github.archive_timeout_seconds');

        try {
            $this->tokens->with($source->installation, $source->github_repository_id, function (string $token) use ($source, $sha, $target, $max, $timeout) {
                $url = rtrim((string) config('github.api_url'), '/').GitHubApiClient::path('/repos/%s/%s/zipball/%s', $source->owner_login, $source->repository_name, $sha);

                for ($hop = 0; ; $hop++) {
                    $response = $this->client->stream($url, $token, $timeout, [404 => 'github.repository_not_found']);

                    if (! $response->redirect()) {
                        break;
                    }

                    if ($hop >= self::MAX_REDIRECTS) {
                        throw GitHubError::of('github.archive_download_failed');
                    }

                    $url = $response->header('Location'); // checked by stream() before it is requested
                }

                $length = $response->header('Content-Length');
                if ($length !== '' && (int) $length > $max) {
                    throw GitHubError::of('github.archive_too_large');
                }

                $this->copy($response->toPsrResponse()->getBody(), $target, $max);
            });
        } catch (GitHubError $e) {
            @unlink($target);

            throw $e;
        } catch (Throwable $e) {
            @unlink($target);
            // Transport messages can carry the signed archive URL: log the type only.
            Log::warning('GitHub archive download failed', ['exception' => $e::class]);

            throw GitHubError::of('github.archive_download_failed');
        }
    }

    private function copy(StreamInterface $body, string $target, int $max): void
    {
        is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);
        $out = fopen($target, 'wb') ?: throw new \RuntimeException('Could not open the archive file.');
        $received = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK);
                $received += strlen($chunk);

                if ($received > $max) {
                    throw GitHubError::of('github.archive_too_large');
                }

                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
            $body->close();
        }

        if ($received === 0) {
            throw GitHubError::of('github.archive_download_failed');
        }
    }
}
